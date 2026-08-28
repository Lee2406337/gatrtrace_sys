<?php
namespace App;

use App\Repository\EventsRepository;
use App\Repository\ContractsRepository;

/**
 * 後台「資料匯入」的 CSV 解析／驗證／查重複。刻意不用任何 Excel 函式庫（本機 PHP
 * 沒裝、也沒開 zip 擴充套件）：範本固定用 CSV，Excel 可以直接開啟/編輯/另存。
 */
final class ImportService
{
    public const CONTRACT_HEADERS = ['合約名稱', '頻率', '起始日', '到期日', '單位', '備註'];
    public const EVENT_HEADERS = ['類別', '工作事項', '頻率', '基準值', '單位', '備註'];

    /**
     * 頻率的使用者慣用寫法 → 資料庫實際存的 ENUM 值。events_master.frequency 底層存的是
     * 半年/2年/3年，畫面下拉選單用 frequency_label() 顯示成每半年/每兩年/每三年，但選單
     * 送出的還是底層值；CSV 匯入沒有下拉選單擋著，使用者會直接打畫面上看到的「每半年」
     * 這種寫法，所以兩種寫法都接受，正規化成 ENUM 實際存的值再驗證/寫入。
     */
    private const EVENT_FREQUENCY_ALIASES = [
        '每半年' => EventFrequency::HalfYear,
        '每兩年' => EventFrequency::TwoYear,
        '每三年' => EventFrequency::ThreeYear,
    ];

    /**
     * 去 UTF-8 BOM、把非 UTF-8 內容（Windows Excel「另存新檔」常見的 Big5）轉碼成 UTF-8。
     * 對已經是 UTF-8 的內容是安全的 no-op，讓 preview→commit 兩階段可以重複呼叫。
     */
    public static function decode(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = @mb_convert_encoding($content, 'UTF-8', 'BIG5');
            if ($converted !== false) {
                $content = $converted;
            }
        }
        return $content;
    }

    /**
     * 解析成列陣列，固定跳過第 1 列（標題列）與整列空白的列。
     * line 是原始行號（從 1 算，標題列算第 1 行），用來在錯誤報告裡指出「第幾列」。
     * @return array<int, array{line:int, cols:array<int,string>}>
     */
    public static function parseCsv(string $content): array
    {
        $content = self::decode($content);
        $lines = preg_split('/\r\n|\r|\n/', $content);

        // Windows 正式機的 PHP 行程序 LC_CTYPE 預設是 Big5（Chinese (Traditional)_Taiwan.950），
        // str_getcsv() 在這個 locale 下對特定中文字（例如「每週」「每月」單獨出現在逗號前）會
        // 誤判欄位邊界，導致該逗號沒有被當成分隔符號、兩欄被黏成一欄（本機 XAMPP 因為預設
        // locale 不同所以測不出來）。暫時切成 C locale 讓 str_getcsv() 用純位元組比對分隔符號，
        // 解析完再還原，避免影響其他依賴伺服器 locale 的程式碼。
        $prevLocale = setlocale(LC_CTYPE, 0);
        setlocale(LC_CTYPE, 'C');
        try {
            $rows = [];
            foreach ($lines as $i => $line) {
                $lineNo = $i + 1;
                if ($lineNo === 1 || trim($line) === '') {
                    continue;
                }
                $cols = array_map(function ($c) {
                    return trim((string) $c);
                }, str_getcsv($line));
                $rows[] = ['line' => $lineNo, 'cols' => $cols];
            }
            return $rows;
        } finally {
            setlocale(LC_CTYPE, $prevLocale);
        }
    }

    /** @return array{data: ?array, error: ?string} */
    public static function validateContractRow(array $cols): array
    {
        [$name, $frequency, $startRaw, $endRaw, $department, $note] = array_pad($cols, 6, '');

        if ($name === '') {
            return ['data' => null, 'error' => '合約名稱為必填'];
        }
        // 單位在資料庫層雖然允許空（合約清單本身有人會直接瀏覽，留空風險較低），但匯入是大量寫入、
        // 品質要求要比手動新增更嚴格，所以匯入時單位一律必填，跟例行事項匯入的規則一致
        if (!in_array($department, Departments::ALL, true)) {
            return ['data' => null, 'error' => "單位不是有效值「{$department}」"];
        }
        $startDate = null;
        if ($startRaw !== '') {
            $startDate = DateParser::parse($startRaw);
            if ($startDate === null) {
                return ['data' => null, 'error' => "起始日格式不正確「{$startRaw}」"];
            }
        }
        $endDate = null;
        if ($endRaw !== '') {
            $endDate = DateParser::parse($endRaw);
            if ($endDate === null) {
                return ['data' => null, 'error' => "到期日格式不正確「{$endRaw}」"];
            }
        }

        return ['data' => [
            'contract_name' => $name,
            'frequency'     => $frequency === '' ? null : $frequency,
            'start_date'    => $startDate,
            'end_date_raw'  => $endRaw === '' ? null : $endRaw,
            'end_date'      => $endDate,
            'department'    => $department,
            'note'          => $note === '' ? null : $note,
        ], 'error' => null];
    }

    /** @return array{data: ?array, error: ?string} */
    public static function validateEventRow(array $cols): array
    {
        [$category, $taskName, $frequency, $baseline, $department, $note] = array_pad($cols, 6, '');
        $frequency = self::EVENT_FREQUENCY_ALIASES[$frequency] ?? $frequency;

        if ($category === '') {
            return ['data' => null, 'error' => '類別為必填'];
        }
        if ($taskName === '') {
            return ['data' => null, 'error' => '工作事項為必填'];
        }
        if (!in_array($frequency, EventFrequency::selectableValues(), true)) {
            return ['data' => null, 'error' => "頻率不是有效值「{$frequency}」"];
        }
        // 單位在例行事項是必填（跟代辦/合約不同，events_master.department 不允許空）
        if (!in_array($department, Departments::ALL, true)) {
            return ['data' => null, 'error' => "單位不是有效值「{$department}」"];
        }
        // 「每年」的基準值是 MM-DD（不帶年），但 Excel 常自動把它補成完整日期
        // （例如 08-11 變成 2026/8/11），一律轉回 BaselineFormat::parseMonthDay() 要的格式再驗證
        if ($frequency === EventFrequency::Yearly) {
            $baseline = self::normalizeMonthDay($baseline);
        }
        // 「每兩年」「每三年」的基準值是完整日期 YYYY-MM-DD，但 Excel 常把打的日期自動改成
        // YYYY/MM/DD（用 / 顯示/存回 CSV），BaselineFormat::parseFullDate() 只認 -，先正規化再驗證
        if (in_array($frequency, [EventFrequency::TwoYear, EventFrequency::ThreeYear], true)) {
            $baseline = str_replace('/', '-', trim($baseline));
        }
        $err = BaselineValidator::validate($frequency, $baseline);
        if ($err !== null) {
            return ['data' => null, 'error' => $err];
        }

        return ['data' => [
            'category'       => $category,
            'task_name'      => $taskName,
            'frequency'      => $frequency,
            'baseline_value' => $baseline === '' ? null : $baseline,
            'department'     => $department,
            'note'           => $note === '' ? null : $note,
        ], 'error' => null];
    }

    /**
     * 逐列驗證＋查重複（合約名稱去頭尾空白後完全相同視為重複）。
     * @return array<int, array{line:int, status:string, message:?string, data:?array, cols:array}>
     */
    public static function classifyContractRows(\PDO $pdo, array $parsedRows): array
    {
        $results = [];
        $seen = [];
        foreach ($parsedRows as $row) {
            ['data' => $data, 'error' => $error] = self::validateContractRow($row['cols']);
            if ($error !== null) {
                $results[] = ['line' => $row['line'], 'status' => 'error', 'message' => $error, 'data' => null, 'cols' => $row['cols']];
                continue;
            }
            $key = $data['contract_name'];
            if (isset($seen[$key]) || self::contractExists($pdo, $data)) {
                $results[] = ['line' => $row['line'], 'status' => 'duplicate', 'message' => '已有相同名稱的合約，跳過', 'data' => null, 'cols' => $row['cols']];
                continue;
            }
            $seen[$key] = true;
            $results[] = ['line' => $row['line'], 'status' => 'ok', 'message' => null, 'data' => $data, 'cols' => $row['cols']];
        }
        return $results;
    }

    /**
     * 逐列驗證＋查重複（類別＋工作事項＋單位 全部相同視為重複——這是「同一份例行事項」的識別方式）。
     * @return array<int, array{line:int, status:string, message:?string, data:?array, cols:array}>
     */
    public static function classifyEventRows(\PDO $pdo, array $parsedRows): array
    {
        $results = [];
        $seen = [];
        foreach ($parsedRows as $row) {
            ['data' => $data, 'error' => $error] = self::validateEventRow($row['cols']);
            if ($error !== null) {
                $results[] = ['line' => $row['line'], 'status' => 'error', 'message' => $error, 'data' => null, 'cols' => $row['cols']];
                continue;
            }
            $key = $data['category'] . '|' . $data['task_name'] . '|' . $data['department'];
            if (isset($seen[$key]) || self::eventExists($pdo, $data)) {
                $results[] = ['line' => $row['line'], 'status' => 'duplicate', 'message' => '已有相同的類別＋工作事項＋單位，跳過', 'data' => null, 'cols' => $row['cols']];
                continue;
            }
            $seen[$key] = true;
            $results[] = ['line' => $row['line'], 'status' => 'ok', 'message' => null, 'data' => $data, 'cols' => $row['cols']];
        }
        return $results;
    }

    /**
     * 把 Excel 自動補成完整日期的 MM-DD 基準值轉回去（例如 08-11 被自動補成 2026/8/11
     * 或 2026-08-11，這裡一律取月/日部分並補零；不符合任何日期樣式的字串原樣傳回，
     * 交給 BaselineFormat::parseMonthDay() 判斷是否合法，錯誤訊息維持既有那一套）。
     */
    private static function normalizeMonthDay(string $b): string
    {
        $b = trim($b);
        if (preg_match('#^(?:\d{4}[-/])?(\d{1,2})[-/](\d{1,2})$#', $b, $m)) {
            return str_pad($m[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
        }
        // Excel 中文地區的另一種自動格式化：「8月11日」（可能還帶年變成「2026年8月11日」）
        if (preg_match('/^(?:\d{4}年)?(\d{1,2})月(\d{1,2})日$/u', $b, $m)) {
            return str_pad($m[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
        }
        return $b;
    }

    private static function eventExists(\PDO $pdo, array $data): bool
    {
        return (new EventsRepository($pdo))
            ->existsByCategoryTaskDepartment($data['category'], $data['task_name'], $data['department']);
    }

    private static function contractExists(\PDO $pdo, array $data): bool
    {
        return (new ContractsRepository($pdo))->existsByName($data['contract_name']);
    }

    /** 動態產生範本 CSV（欄位跟解析器共用同一份常數，不會漂移），含 BOM 方便 Excel 直接雙擊開啟辨識 UTF-8 */
    public static function template(string $type): string
    {
        $rows = $type === 'contracts'
            ? [self::CONTRACT_HEADERS, ['辦公室影印機租賃', '每年', '2026-01-01', '2027-01-01', '林口總務', '範例列，可刪除']]
            : [self::EVENT_HEADERS, ['環安', '消防設備檢查', '每月', '15', '林口總務', '範例列，可刪除']];
        $out = "\xEF\xBB\xBF";
        foreach ($rows as $r) {
            $out .= implode(',', array_map([self::class, 'csvEscape'], $r)) . "\r\n";
        }
        return $out;
    }

    private static function csvEscape(string $v): string
    {
        if (preg_match('/[,"\r\n]/', $v)) {
            return '"' . str_replace('"', '""', $v) . '"';
        }
        return $v;
    }
}
