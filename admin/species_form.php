<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('species.manage');

$pdo = db();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$species = $id ? getSpeciesById($pdo, $id) : null;
if ($id && !$species) {
    http_response_code(404);
    exit('ไม่พบชนิดพันธุ์นี้');
}

$errors = [];
$categories = getAllCategories($pdo);
$subtypes = getAllSubtypes($pdo);
$zones = getAllZones($pdo);
$stockRows = $id ? getStockForSpecies($pdo, $id) : [];
$saleStatusLabels = ['available' => 'พร้อมขาย', 'reserved' => 'จองแล้ว', 'sold_out' => 'ขายหมด', 'not_for_sale' => 'ไม่ขาย'];

// Thai-only fields. English/Chinese are never entered here — they're
// generated automatically the first time a visitor views a tree page in
// that language (see includes/translation.php, called from public/tree.php)
// and cached on these same columns, so this form must never overwrite them.
$detailSections = [
    'care_instructions' => 'วิธีดูแล',
    'characteristics' => 'ลักษณะ',
    'properties' => 'คุณสมบัติ',
    'benefits' => 'ประโยชน์',
    'cautions' => 'ข้อควรระวัง (ผลเสีย / ผู้ที่ควรหลีกเลี่ยง)',
    'part_uses' => 'การใช้ประโยชน์แต่ละส่วน (เช่น ดอก: ..., ผล: ..., ลำต้น: ...)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Category is its own required choice (subtype_form.php lets a subtype
    // exist without a category yet — "เพิ่มชนิด" only asks for a name — so
    // it can't always be relied on to supply the category here).
    $categoryCode = trim($_POST['category_code'] ?? '');
    $subtypeId = (int) ($_POST['subtype_id'] ?? 0);
    $subtype = $subtypeId ? getSubtypeById($pdo, $subtypeId) : null;
    // species_code / classification_id are never admin-entered (see the
    // form below) — a hidden field carries the existing value forward on
    // edit, and is simply absent when creating, which the logic below
    // already treats as "assign automatically" / "not set".
    $speciesCode = trim($_POST['species_code'] ?? '');
    $classificationId = trim($_POST['classification_id'] ?? '') ?: null;
    $name = trim($_POST['name'] ?? '');
    $nameCommon = trim($_POST['name_common'] ?? '') ?: null;
    $nameScientific = trim($_POST['name_scientific'] ?? '') ?: null;
    $description = trim($_POST['description'] ?? '') ?: null;

    $detailValues = [];
    foreach (array_keys($detailSections) as $field) {
        $detailValues[$field] = trim($_POST[$field] ?? '') ?: null;
    }

    // Optional: immediately stock this species into a zone — pick a zone and
    // a quantity, and the system creates that many individual tree rows
    // (each with its own Tree ID / QR / plant_code) instead of the admin
    // having to add them one at a time.
    $plantZoneId = (int) ($_POST['plant_zone_id'] ?? 0);
    $plantQuantity = max(0, (int) ($_POST['plant_quantity'] ?? 0));
    if ($plantZoneId && !getZoneById($pdo, $plantZoneId)) {
        $errors[] = 'กรุณาเลือกโซนให้ถูกต้อง';
    }

    if ($name === '') {
        $errors[] = 'กรุณาระบุชื่อ';
    }
    if (!getCategoryByCode($pdo, $categoryCode)) {
        $errors[] = 'กรุณาเลือกประเภทพืชให้ถูกต้อง';
    }
    if (!$subtype) {
        $errors[] = 'กรุณาเลือกชนิดให้ถูกต้อง';
    } elseif ($subtype['category_code'] !== null && $subtype['category_code'] !== $categoryCode) {
        // Shouldn't happen via the UI (the subtype dropdown is filtered to
        // the chosen category, plus any not-yet-assigned ones) — only
        // reachable by tampering with the form. Fail safe.
        $errors[] = 'ชนิดที่เลือกไม่ตรงกับประเภทพืชที่เลือก';
    }
    // Species code: assigned automatically when blank (always true for a
    // new species, since the admin is never shown this field at all).
    if ($speciesCode === '') {
        if ($categoryCode !== '' && getCategoryByCode($pdo, $categoryCode)) {
            $speciesCode = nextSpeciesCode($pdo, $categoryCode);
        }
    } elseif ($categoryCode !== '') {
        $dupCodeStmt = $pdo->prepare('SELECT name FROM species WHERE category_code = :cc AND species_code = :sc AND id != :id');
        $dupCodeStmt->execute(['cc' => $categoryCode, 'sc' => $speciesCode, 'id' => $id]);
        $codeConflict = $dupCodeStmt->fetchColumn();
        if ($codeConflict !== false) {
            $errors[] = "รหัสชนิดพืช \"$speciesCode\" ในประเภทนี้ถูกใช้โดย \"$codeConflict\" แล้ว";
        }
    }
    if ($classificationId !== null && !isValidClassificationIdFormat($classificationId)) {
        // Can only happen if someone tampers with the hidden field directly —
        // the admin never types this. Fail safe by dropping it rather than
        // blocking the whole save over a field they can't even see.
        $classificationId = null;
    }

    if (!$errors) {
        $params = [
            'category_code' => $categoryCode, 'subtype_id' => $subtypeId, 'species_code' => $speciesCode,
            'classification_id' => $classificationId,
            'name' => $name, 'name_common' => $nameCommon, 'name_scientific' => $nameScientific,
            'description' => $description,
        ] + $detailValues;
        $columns = array_keys($params);
        try {
            if ($id) {
                $setSql = implode(', ', array_map(fn($c) => "$c=:$c", $columns));
                $stmt = $pdo->prepare("UPDATE species SET $setSql WHERE id=:id");
                $stmt->execute($params + ['id' => $id]);
                $speciesId = $id;
            } else {
                $colSql = implode(', ', $columns);
                $placeholderSql = implode(', ', array_map(fn($c) => ":$c", $columns));
                $stmt = $pdo->prepare("INSERT INTO species ($colSql) VALUES ($placeholderSql)");
                $stmt->execute($params);
                $speciesId = (int) $pdo->lastInsertId();
            }

            // First time this subtype is actually used for a species — link
            // it to the chosen category permanently (subtype_form.php's
            // "เพิ่มชนิด" never asks for one, so this is how most subtypes
            // end up assigned).
            if ($subtype['category_code'] === null) {
                $pdo->prepare('UPDATE subtypes SET category_code = :cc WHERE id = :id')
                    ->execute(['cc' => $categoryCode, 'id' => $subtype['id']]);
            }

            // category_code/species_code feed directly into every one of this
            // species' trees' plant_code — recompute them all now.
            $affectedTreeIds = $pdo->prepare('SELECT id FROM trees WHERE species_id = :sid');
            $affectedTreeIds->execute(['sid' => $speciesId]);
            foreach ($affectedTreeIds->fetchAll(PDO::FETCH_COLUMN) as $affectedTreeId) {
                recomputeTreePlantCode($pdo, (int) $affectedTreeId);
            }

            // Bulk-create individual tree rows for this species, if requested.
            if ($plantZoneId && $plantQuantity > 0) {
                $maxOrder = (int) $pdo->query('SELECT COALESCE(MAX(display_order), 0) FROM trees')->fetchColumn();
                $insertTree = $pdo->prepare(
                    'INSERT INTO trees (species_id, zone_id, area_code, display_order, is_active) VALUES (:sp, :z, :a, :o, 1)'
                );
                for ($i = 1; $i <= $plantQuantity; $i++) {
                    $insertTree->execute(['sp' => $speciesId, 'z' => $plantZoneId, 'a' => '01', 'o' => $maxOrder + $i]);
                    $newTreeId = (int) $pdo->lastInsertId();
                    $qrPath = generateTreeQrCode($newTreeId);
                    $pdo->prepare('UPDATE trees SET qr_code_path = :qr WHERE id = :id')->execute(['qr' => $qrPath, 'id' => $newTreeId]);
                    recomputeTreePlantCode($pdo, $newTreeId);
                }
            }

            header('Location: species.php');
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'ข้อมูลนี้เพิ่งถูกใช้โดยการบันทึกอื่น กรุณาลองใหม่อีกครั้ง';
            } else {
                throw $e;
            }
        }
    }

    $species = array_merge($species ?? [], [
        'category_code' => $categoryCode, 'subtype_id' => $subtypeId, 'species_code' => $speciesCode,
        'classification_id' => $classificationId,
        'name' => $name, 'name_common' => $nameCommon, 'name_scientific' => $nameScientific,
        'description' => $description,
        'plant_zone_id' => $plantZoneId, 'plant_quantity' => $plantQuantity,
    ] + $detailValues);
}

$v = fn($key, $default = '') => e((string) ($species[$key] ?? $default));
$currentCode = $species['species_code'] ?? '';
$currentClassificationId = $species['classification_id'] ?? '';
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $id ? 'แก้ไข' : 'เพิ่ม' ?>ชนิดพันธุ์</title>
<link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body>
<div class="admin-wrap admin-wrap-narrow">
  <p><a class="btn-outline btn-sm" href="species.php">&larr; กลับไปหน้าชนิดพันธุ์</a></p>
  <h1><?= $id ? 'แก้ไขชนิดพันธุ์' : 'เพิ่มชนิดพันธุ์' ?></h1>
  <p class="field-hint">กรอกเป็นภาษาไทยอย่างเดียว — ระบบจะแปลเป็นอังกฤษ/จีนให้อัตโนมัติตอนผู้เข้าชมเปิดหน้าต้นไม้ด้วยภาษานั้น<?= AI_ENABLED ? '' : ' (ต้องตั้งค่า Gemini API key ก่อน — ดูที่หน้า <a href="settings.php#ai-translation">ตั้งค่า</a>)' ?></p>

  <?php foreach ($errors as $err): ?>
    <div class="flash error"><?= e($err) ?></div>
  <?php endforeach; ?>

  <?php if (!$subtypes): ?>
    <div class="flash error">ยังไม่มีชนิดในระบบ กรุณา<a href="subtype_form.php">เพิ่มชนิด</a>ก่อน</div>
  <?php else: ?>

  <form method="post">
    <label for="category_code">ประเภทพืช</label>
    <select id="category_code" name="category_code" required onchange="filterSubtypesByCategory(this.value)">
      <option value="">— เลือกประเภทพืช —</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= e($cat['code']) ?>" <?= ($species['category_code'] ?? '') === $cat['code'] ? 'selected' : '' ?>>
          <?= e($cat['name_th']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label for="subtype_id">ชนิด</label>
    <select id="subtype_id" name="subtype_id" required>
      <option value="">— เลือกชนิด —</option>
      <?php foreach ($subtypes as $st): ?>
        <option value="<?= (int) $st['id'] ?>" data-category="<?= e($st['category_code'] ?? '') ?>"
          <?= (int) ($species['subtype_id'] ?? 0) === (int) $st['id'] ? 'selected' : '' ?>>
          <?= e($st['name_th']) ?><?= $st['category_code'] === null ? ' (ยังไม่กำหนดประเภท)' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
    <p class="field-hint">โครงสร้าง: ประเภท &rarr; ชนิด &rarr; ชื่อต้นไม้ — จัดการรายการได้ที่ <a href="categories.php">ประเภทพืช</a> และ <a href="subtypes.php">ชนิด</a> เลือกชนิดที่ "ยังไม่กำหนดประเภท" ได้เลย ระบบจะผูกเข้ากับประเภทที่เลือกไว้ด้านบนให้อัตโนมัติตอนบันทึก</p>

    <?php // รหัสชนิดพืช / รหัสจำแนกพันธุ์ — รหัสภายในของระบบ ไม่ต้องให้ Admin เห็นหรือกรอกเอง
          // ระบบสร้าง/คงค่าเดิมให้อัตโนมัติผ่านฟิลด์ที่ซ่อนไว้นี้ ?>
    <?php if ($currentCode !== ''): ?>
      <input type="hidden" name="species_code" value="<?= e($currentCode) ?>">
    <?php endif; ?>
    <?php if ($currentClassificationId !== ''): ?>
      <input type="hidden" name="classification_id" value="<?= e($currentClassificationId) ?>">
    <?php endif; ?>

    <label for="name">ชื่อ (ไทย)</label>
    <input type="text" id="name" name="name" value="<?= $v('name') ?>" required>

    <label for="name_common">ชื่อสามัญ</label>
    <input type="text" id="name_common" name="name_common" value="<?= $v('name_common') ?>">

    <label for="name_scientific">ชื่อวิทยาศาสตร์</label>
    <input type="text" id="name_scientific" name="name_scientific" value="<?= $v('name_scientific') ?>" placeholder="เช่น Cassia fistula">

    <label for="description">คำอธิบาย (ไทย)</label>
    <textarea id="description" name="description" rows="4"><?= $v('description') ?></textarea>

    <?php foreach ($detailSections as $field => $label): ?>
    <label for="<?= $field ?>"><?= e($label) ?> (ไทย)</label>
    <textarea id="<?= $field ?>" name="<?= $field ?>" rows="3"><?= $v($field) ?></textarea>
    <?php endforeach; ?>

    <div class="history-section">
      <h2>เพิ่มต้นไม้ของชนิดพันธุ์นี้ทันที (ไม่บังคับ)</h2>
      <label for="plant_zone_id">โซน</label>
      <select id="plant_zone_id" name="plant_zone_id">
        <option value="">— ไม่เพิ่มต้นไม้ตอนนี้ —</option>
        <?php foreach ($zones as $z): ?>
          <option value="<?= (int) $z['id'] ?>" <?= (int) ($species['plant_zone_id'] ?? 0) === (int) $z['id'] ? 'selected' : '' ?>>
            <?= e($z['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label for="plant_quantity">จำนวนต้น</label>
      <input type="number" id="plant_quantity" name="plant_quantity" min="0" value="<?= $v('plant_quantity', '0') ?>">
      <p class="field-hint">
        เลือกโซนแล้วใส่จำนวน ระบบจะสร้างต้นไม้ตามจำนวนนั้นให้อัตโนมัติ (แต่ละต้นมี Tree ID/QR ของตัวเอง) —
        ไม่ต้องเพิ่มทีละต้น แก้ไขเป็นรายต้นได้ภายหลังที่หน้าต้นไม้
      </p>
    </div>

    <p><button class="btn" type="submit">บันทึก</button></p>
  </form>

  <script>
  function filterSubtypesByCategory(categoryCode) {
    var select = document.getElementById('subtype_id');
    var options = select.querySelectorAll('option[value]:not([value=""])');
    options.forEach(function (opt) {
      // A subtype with no category yet (empty data-category) stays
      // selectable under any category — picking it links it to whichever
      // category is chosen here (see species_form.php's save handler).
      opt.hidden = !!categoryCode && !!opt.dataset.category && opt.dataset.category !== categoryCode;
    });
    var current = select.options[select.selectedIndex];
    if (current && current.hidden) {
      select.value = '';
    }
  }
  filterSubtypesByCategory(document.getElementById('category_code').value);
  </script>
  <?php endif; ?>

  <?php if ($id): ?>
  <section id="stock" class="history-section">
    <h2>สต็อกและช่องทางขาย (Nursery Stock)</h2>
    <?php if ($stockRows): ?>
      <div class="table-scroll">
        <table>
          <thead><tr><th>ขนาด</th><th>จำนวน</th><th>ราคา</th><th>สถานะ</th><th>ช่องทางขาย</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($stockRows as $stock): ?>
            <tr>
              <td><?= e($stock['size_label'] ?? '') ?></td>
              <td><?= (int) $stock['quantity'] ?></td>
              <td><?= $stock['price'] !== null ? number_format((float) $stock['price'], 2) : '—' ?></td>
              <td><?= e($saleStatusLabels[$stock['sale_status']] ?? $stock['sale_status']) ?></td>
              <td><?= e($stock['sales_channel'] ?? '') ?></td>
              <td>
                <form class="inline" method="post" action="stock_delete.php" data-confirm="ลบรายการนี้?">
                  <input type="hidden" name="id" value="<?= (int) $stock['id'] ?>">
                  <input type="hidden" name="species_id" value="<?= (int) $id ?>">
                  <button class="btn btn-sm btn-danger" type="submit">ลบ</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="muted-note">ยังไม่มีข้อมูลสต็อก</p>
    <?php endif; ?>

    <form method="post" action="stock_add.php" class="inline-add-form">
      <input type="hidden" name="species_id" value="<?= (int) $id ?>">
      <div class="field-row">
        <label>ขนาด<input type="text" name="size_label" placeholder="เช่น เล็ก/กลาง/ใหญ่"></label>
        <label>จำนวน<input type="number" name="quantity" min="0" value="0" required></label>
        <label>ราคา (บาท)<input type="text" name="price" inputmode="decimal" placeholder="เช่น 350"></label>
        <label>สถานะ
          <select name="sale_status">
            <?php foreach ($saleStatusLabels as $key => $label): ?>
              <option value="<?= e($key) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>ช่องทางขาย<input type="text" name="sales_channel" placeholder="เช่น เรือนเพาะชำ, ออนไลน์"></label>
      </div>
      <button class="btn btn-sm" type="submit">+ เพิ่มรายการสต็อก</button>
    </form>
  </section>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_confirm_modal.php'; ?>
</body>
</html>
