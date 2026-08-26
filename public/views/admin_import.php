<h1>資料匯入</h1>
<?php if (!empty($_GET['err'])): ?>
  <p class="cell-red pad-sm"><?= htmlspecialchars($_GET['err']) ?></p>
<?php endif; ?>
<p style="color:#666;">上傳 CSV 檔案匯入例行事項或合約。請先下載範本、依欄位填好資料後上傳；上傳後會先顯示預覽（哪些會匯入、哪些被跳過及原因），確認無誤才會真正寫入。</p>

<h2>匯入例行事項</h2>
<p><a class="btn btn-secondary btn-sm" href="admin.php?r=import&action=template&type=events">下載例行事項範本 CSV</a></p>
<form method="post" action="admin.php?r=import&action=preview" enctype="multipart/form-data" class="flex gap-6">
  <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
  <input type="hidden" name="type" value="events">
  <input type="file" name="file" accept=".csv" class="w-auto" required>
  <button class="btn" type="submit">上傳並預覽</button>
</form>

<h2>匯入合約</h2>
<p><a class="btn btn-secondary btn-sm" href="admin.php?r=import&action=template&type=contracts">下載合約範本 CSV</a></p>
<form method="post" action="admin.php?r=import&action=preview" enctype="multipart/form-data" class="flex gap-6">
  <input type="hidden" name="_csrf" value="<?= \App\Csrf::token() ?>">
  <input type="hidden" name="type" value="contracts">
  <input type="file" name="file" accept=".csv" class="w-auto" required>
  <button class="btn" type="submit">上傳並預覽</button>
</form>
