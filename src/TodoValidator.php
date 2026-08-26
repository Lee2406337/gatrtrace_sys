<?php
namespace App;

final class TodoValidator
{
    /** monthly_todos.status 的合法值，單一事實來源，供表單下拉/後台白名單共用；
     *  順序即畫面下拉選單順序（已完成排在簽核中前面，是既有明確需求，勿調整）。 */
    public const STATUSES = ['未開始', '進行中', '已完成', '簽核中', '異常'];

    /** 違規回錯誤訊息，否則 null（spec §4.2） */
    public static function validate(string $status, ?string $followUp, ?string $note): ?string
    {
        if ($status === '已完成' && trim((string) $followUp) === '') {
            return '狀態為「已完成」時，後續辦理事項為必填。';
        }
        if ($status === '異常' && !str_contains((string) $note, '異常')) {
            return '狀態為「異常」時，備註須含「異常」字樣及原因。';
        }
        return null;
    }
}
