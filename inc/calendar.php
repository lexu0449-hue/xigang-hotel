<?php
/**
 * 房态日历 — Calendar availability view (with integrated prices)
 * 支持单日/批量修改可用数、锁定/解锁，价格行始终显示并可点击编辑
 */
$calYear = (int)($_GET['year'] ?? date('Y'));
$calMonth = (int)($_GET['month'] ?? date('n'));
$hotelId = (int)($_GET['hotel_id'] ?? 0);

$hotels = $db->query("SELECT id, name FROM hotels ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// All rooms with hotel name
$roomSql = "SELECT r.*, h.name hn FROM rooms r JOIN hotels h ON r.hotel_id=h.id WHERE r.is_active=1";
$roomParams = [];
if ($hotelId) { $roomSql .= " AND r.hotel_id=?"; $roomParams[] = $hotelId; }
$roomSql .= " ORDER BY r.hotel_id, r.id";
$rooms = $db->prepare($roomSql); $rooms->execute($roomParams);
$allRooms = $rooms->fetchAll(PDO::FETCH_ASSOC);

// Group by hotel
$hotelRooms = [];
foreach ($allRooms as $r) {
    $hid = $r['hotel_id'];
    if (!isset($hotelRooms[$hid])) $hotelRooms[$hid] = ['name'=>$r['hn'], 'rooms'=>[]];
    $hotelRooms[$hid]['rooms'][] = $r;
}

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $calMonth, $calYear);
$monthStart = sprintf('%04d-%02d-01', $calYear, $calMonth);
$monthEnd = sprintf('%04d-%02d-%02d', $calYear, $calMonth, $daysInMonth);

// Get all active bookings in this month
$bookSql = "SELECT b.*, r.id rid FROM bookings b JOIN rooms r ON b.room_id=r.id WHERE b.status NOT IN ('cancelled','refunded') AND b.check_in < ? AND b.check_out > ?";
$bookParams = [date('Y-m-d', strtotime($monthEnd . ' +1 day')), $monthStart];
if ($hotelId) { $bookSql = "SELECT b.*, r.id rid FROM bookings b JOIN rooms r ON b.room_id=r.id WHERE r.hotel_id=? AND b.status NOT IN ('cancelled','refunded') AND b.check_in < ? AND b.check_out > ?"; $bookParams = [$hotelId, date('Y-m-d', strtotime($monthEnd . ' +1 day')), $monthStart]; }
$bookings = $db->prepare($bookSql); $bookings->execute($bookParams);

// Build occupancy map [room_id][day] = count
$occMap = [];
foreach ($bookings->fetchAll(PDO::FETCH_ASSOC) as $b) {
    $rid = $b['rid'];
    $ci = max(strtotime($b['check_in']), strtotime($monthStart));
    $co = min(strtotime($b['check_out']), strtotime($monthEnd));
    for ($t = $ci; $t < $co; $t += 86400) {
        $day = (int)date('j', $t);
        if (!isset($occMap[$rid])) $occMap[$rid] = [];
        if (!isset($occMap[$rid][$day])) $occMap[$rid][$day] = 0;
        $occMap[$rid][$day]++;
    }
}

// Load manual availability settings for this month
$availSql = "SELECT ra.* FROM room_availability ra
             JOIN rooms r ON ra.room_id = r.id
             WHERE ra.date >= ? AND ra.date <= ?";
$availParams = [$monthStart, $monthEnd];
if ($hotelId) {
    $availSql .= " AND r.hotel_id = ?";
    $availParams[] = $hotelId;
}
$availStmt = $db->prepare($availSql);
$availStmt->execute($availParams);
$availRows = $availStmt->fetchAll(PDO::FETCH_ASSOC);

// Build manual availability map [room_id][day] = {available_rooms, is_locked}
$availMap = [];
foreach ($availRows as $row) {
    $rid = (int)$row['room_id'];
    $day = (int)date('j', strtotime($row['date']));
    if (!isset($availMap[$rid])) $availMap[$rid] = [];
    $availMap[$rid][$day] = [
        'available_rooms' => (int)$row['available_rooms'],
        'is_locked' => (int)$row['is_locked'],
        'notes' => $row['notes']
    ];
}

/* ────────── 价格数据（PHP端计算） ────────── */
$allRules = $db->query("SELECT * FROM price_rules WHERE start_date <= '$monthEnd' AND end_date >= '$monthStart' ORDER BY priority DESC")->fetchAll(PDO::FETCH_ASSOC);
$rulesByRoom = [];
foreach ($allRules as $rule) {
    $rulesByRoom[(int)$rule['room_id']][] = $rule;
}

// Build price map [room_id][day] = {price, base_price, rule_name, diff}
$priceMap = [];
foreach ($allRooms as $rm) {
    $rid = (int)$rm['id'];
    $basePrice = (float)$rm['price'];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dateStr = sprintf('%04d-%02d-%02d', $calYear, $calMonth, $d);
        $dayPrice = $basePrice;
        $dayRule = null;
        
        if (isset($rulesByRoom[$rid])) {
            foreach ($rulesByRoom[$rid] as $rule) {
                if ($dateStr >= $rule['start_date'] && $dateStr <= $rule['end_date']) {
                    if ($rule['price_override'] !== null && $rule['price_override'] !== '') {
                        $dayPrice = (float)$rule['price_override'];
                        $dayRule = h($rule['name']);
                        break;
                    } elseif ($rule['price_modifier'] !== null && $rule['price_modifier'] !== '') {
                        $mod = (float)$rule['price_modifier'];
                        $dayPrice = $basePrice + $basePrice * $mod / 100;
                        $dayRule = h($rule['name']);
                        break;
                    }
                }
            }
        }
        
        $diff = round($dayPrice - $basePrice, 2);
        $priceMap[$rid][$d] = [
            'price' => round($dayPrice, 2),
            'base_price' => $basePrice,
            'rule_name' => $dayRule,
            'diff' => $diff,
        ];
    }
}

$prevM = $calMonth - 1; $prevY = $calYear;
if ($prevM < 1) { $prevM = 12; $prevY--; }
$nextM = $calMonth + 1; $nextY = $calYear;
if ($nextM > 12) { $nextM = 1; $nextY++; }

$weekdays = ['日','一','二','三','四','五','六'];
?>
<div class="topbar"><h1>📅 房态日历</h1></div>

<div class="filter-bar">
    <select onchange="location='?page=availability&hotel_id='+this.value+'&year=<?= $calYear ?>&month=<?= $calMonth ?>'">
        <option value="0">全部酒店</option>
        <?php foreach ($hotels as $h): ?>
        <option value="<?= $h['id'] ?>" <?= $hotelId==$h['id']?'selected':'' ?>><?= h($h['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <a href="?page=availability&hotel_id=<?= $hotelId ?>&year=<?= $prevY ?>&month=<?= $prevM ?>" class="btn" style="text-decoration:none">← 上月</a>
    <span style="font-size:16px;font-weight:600;color:#1a2c3e;padding:0 12px"><?= $calYear ?>年 <?= $calMonth ?>月</span>
    <a href="?page=availability&hotel_id=<?= $hotelId ?>&year=<?= $nextY ?>&month=<?= $nextM ?>" class="btn" style="text-decoration:none">下月 →</a>
    <a href="?page=availability&year=<?= date('Y') ?>&month=<?= date('n') ?>" class="btn" style="text-decoration:none">今天</a>
    <button class="btn" onclick="showBatchModal()" style="text-decoration:none">📦 批量修改</button>
</div>

<style>
.price-row td {
    font-size:11px;
    padding:4px 2px !important;
    border-bottom:2px solid #e0e0e0 !important;
    background:#fafafa;
}
.price-row .price-label {
    position:sticky;left:0;z-index:1;
    background:#fafafa;
    font-size:11px;
    color:#999;
    font-weight:400;
    padding:4px 10px !important;
    border-right:2px solid #e0e0e0;
    white-space:nowrap;
}
.price-cell {
    text-align:center;
    font-weight:600;
    font-size:11px;
    cursor:pointer;
    transition:box-shadow .15s;
}
.price-cell:hover {
    box-shadow:inset 0 0 0 2px #c8a96e;
}
.price-cell.up { background:#ffebee; color:#c62828; }
.price-cell.down { background:#e8f5e9; color:#2e7d32; }
.price-cell.same { background:#f5f5f5; color:#546e7a; }
.price-rule-mark {
    display:inline-block;
    width:4px;height:4px;
    border-radius:50%;
    background:#c8a96e;
    margin-left:2px;
    vertical-align:super;
}
.price-cell .dollar { color:#999; font-weight:400; font-size:10px; }
</style>
<div style="display:flex;gap:16px;margin-bottom:16px;font-size:12px;flex-wrap:wrap">
    <span><span style="display:inline-block;width:14px;height:14px;background:#e8f5e9;border:1px solid #c8e6c9;border-radius:3px;vertical-align:middle"></span> 充足</span>
    <span><span style="display:inline-block;width:14px;height:14px;background:#fff3e0;border:1px solid #ffe0b2;border-radius:3px;vertical-align:middle"></span> 部分已订</span>
    <span><span style="display:inline-block;width:14px;height:14px;background:#ffebee;border:1px solid #ffcdd2;border-radius:3px;vertical-align:middle"></span> 已满</span>
    <span><span style="display:inline-block;width:14px;height:14px;background:#e0e0e0;border:1px solid #bdbdbd;border-radius:3px;vertical-align:middle"></span> 无数据</span>
    <span><span style="display:inline-block;width:14px;height:14px;background:#e8eaf6;border:1px solid #c5cae9;border-radius:3px;vertical-align:middle"></span> 已锁定</span>
    <span style="margin-left:8px"><span style="display:inline-block;width:4px;height:4px;border-radius:50%;background:#c8a96e;vertical-align:middle;margin-right:3px"></span> 有规则</span>
</div>

<?php foreach ($hotelRooms as $hid => $hr): ?>
<?php if ($hotelId && $hotelId != $hid) continue; ?>
<h3 style="font-size:15px;color:#1a2c3e;margin:24px 0 12px">🏨 <?= h($hr['name']) ?></h3>
<div style="overflow-x:auto">
<table style="min-width:800px;font-size:12px;border-collapse:collapse">
    <thead>
        <tr>
            <th style="position:sticky;left:0;z-index:2;background:#1a2c3e;color:#fff;min-width:100px;padding:8px 10px;text-align:left">房型</th>
            <th style="background:#1a2c3e;color:#fff;padding:8px;text-align:center;min-width:30px">总数</th>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $dow = date('w', mktime(0,0,0,$calMonth,$d,$calYear));
                $isWeekend = ($dow == 0 || $dow == 6);
                $bg = $isWeekend ? '#2a4a6e' : '#1a2c3e';
                $fc = $isWeekend ? '#c8a96e' : '#fff';
            ?>
            <th style="background:<?= $bg ?>;color:<?= $fc ?>;padding:4px 2px;text-align:center;font-size:11px;min-width:30px">
                <?= $d ?><br><span style="font-size:9px"><?= $weekdays[$dow] ?></span>
            </th>
            <?php endfor; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($hr['rooms'] as $rm):
            $qty = (int)$rm['quantity'];
            $rid = $rm['id'];
            $basePrice = (float)$rm['price'];
        ?>
        <!-- 可用数行 -->
        <tr data-room-id="<?= $rid ?>" data-base-price="<?= $rm['price'] ?>">
            <td style="position:sticky;left:0;z-index:1;background:#fff;font-weight:600;padding:6px 10px;border-right:2px solid #e0e0e0;white-space:nowrap"><?= h($rm['name']) ?></td>
            <td style="text-align:center;font-weight:600;background:#fafafa;padding:6px"><?= $qty ?></td>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $booked = $occMap[$rid][$d] ?? 0;
                $availSetting = $availMap[$rid][$d] ?? null;
                $isLocked = $availSetting ? $availSetting['is_locked'] : 0;
                $manualAvail = $availSetting ? $availSetting['available_rooms'] : null;
                $hasManual = $manualAvail !== null;

                // 最终可用数 = 如果手动设置了，取 min(默认数量, 手动设置)
                if ($hasManual) {
                    $finalAvail = min($qty, $manualAvail);
                } else {
                    $finalAvail = $qty;
                }
                // 减去已预订房间
                $effectiveAvail = max(0, $finalAvail - $booked);

                if ($isLocked) {
                    $cls = 'locked';
                    $bg = '#e8eaf6';
                    $fc = '#283593';
                    $displayText = '🔒';
                } elseif ($qty == 0) {
                    $cls = 'na';
                    $bg = '#e0e0e0';
                    $fc = '#999';
                    $displayText = '-/0';
                } elseif ($effectiveAvail <= 0) {
                    $cls = 'full';
                    $bg = '#ffebee';
                    $fc = '#c62828';
                    $displayText = $booked . '<span style="font-weight:400;color:#999;font-size:10px">/' . $qty . '</span>';
                } elseif ($booked > 0) {
                    $cls = 'partial';
                    $bg = '#fff3e0';
                    $fc = '#e65100';
                    $displayText = $booked . '<span style="font-weight:400;color:#999;font-size:10px">/' . $qty . '</span>';
                } else {
                    $cls = 'free';
                    $bg = '#e8f5e9';
                    $fc = '#2e7d32';
                    $displayText = '0<span style="font-weight:400;color:#999;font-size:10px">/' . $qty . '</span>';
                }

                // 手动标记
                $manualMark = $hasManual ? '<span style="color:#c8a96e;font-weight:700;font-size:10px">*</span>' : '';
            ?>
            <td style="background:<?= $bg ?>;color:<?= $fc ?>;padding:6px 2px;text-align:center;font-size:11px;font-weight:600;cursor:pointer;position:relative"
                onclick="showDayDetail('<?= sprintf('%04d-%02d-%02d', $calYear, $calMonth, $d) ?>',<?= $rid ?>,'<?= h($rm['name']) ?>',<?= $qty ?>)"
                title="<?= $d ?>日 | 已订:<?= $booked ?> 可用:<?= $effectiveAvail ?> 总房:<?= $qty ?>">
                <?= $displayText ?><br><?= $manualMark ?>
            </td>
            <?php endfor; ?>
        </tr>
        <!-- 价格行（始终显示） -->
        <?php $roomPrices = $priceMap[$rid] ?? []; ?>
        <tr class="price-row">
            <td class="price-label">💰 价格 <span style="font-weight:400;color:#999;font-size:10px">$<?= round($basePrice) ?></span></td>
            <td style="text-align:center;font-size:11px;color:#999;padding:4px 2px;background:#fafafa">-</td>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $pd = $roomPrices[$d] ?? null;
                if (!$pd):
            ?>
            <td class="price-cell same" onclick="editDayPrice(<?= $rid ?>,<?= $d ?>,'<?= sprintf('%04d-%02d-%02d', $calYear, $calMonth, $d) ?>',<?= $basePrice ?>)">-</td>
            <?php else:
                $pc = $pd['price'];
                $diff = $pd['diff'];
                $priceCls = $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'same');
                $ruleMark = $pd['rule_name'] ? '<span class="price-rule-mark" title="' . $pd['rule_name'] . '"></span>' : '';
            ?>
            <td class="price-cell <?= $priceCls ?>" title="<?= h($rm['name']) ?> <?= sprintf('%04d-%02d-%02d', $calYear, $calMonth, $d) ?>：$<?= number_format($pc, 2) ?><?= $pd['rule_name'] ? ' (' . $pd['rule_name'] . ')' : '' ?>"
                onclick="editDayPrice(<?= $rid ?>,<?= $d ?>,'<?= sprintf('%04d-%02d-%02d', $calYear, $calMonth, $d) ?>',<?= $basePrice ?>)">
                $<?= round($pc) ?><?= $ruleMark ?>
            </td>
            <?php endif; endfor; ?>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endforeach; ?>

<!-- 单日详情弹窗 -->
<div class="modal" id="dayModal">
    <div class="modal-box" style="max-width:500px">
        <div class="modal-hd">日期详情 <button class="close" onclick="document.getElementById('dayModal').classList.remove('open')">×</button></div>
        <div class="modal-body" id="dayDetail"><div style="text-align:center;padding:40px;color:#999">加载中...</div></div>
    </div>
</div>

<!-- 批量修改弹窗 -->
<div class="modal" id="batchModal">
    <div class="modal-box" style="max-width:550px">
        <div class="modal-hd">📦 批量修改 <button class="close" onclick="closeBatchModal()">×</button></div>
        <div class="modal-body">
            <div class="field">
                <label>开始日期</label>
                <input type="date" id="batchDateFrom" value="<?= $monthStart ?>">
            </div>
            <div class="field">
                <label>结束日期</label>
                <input type="date" id="batchDateTo" value="<?= $monthEnd ?>">
            </div>
            <div class="field">
                <label>选择房型</label>
                <select id="batchRoomId" style="max-height:200px">
                    <option value="0">全部房型</option>
                    <?php foreach ($allRooms as $rm): ?>
                    <option value="<?= $rm['id'] ?>"><?= h($rm['hn']) ?> — <?= h($rm['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="border-top:1px solid #eee;margin:12px 0;padding-top:12px">
                <label style="font-size:14px;font-weight:600;color:#1a2c3e">修改类型</label>
                <div style="display:flex;gap:12px;margin:8px 0">
                    <label><input type="radio" name="batchMode" value="availability" checked onchange="toggleBatchMode()"> 🛏️ 可用房数</label>
                    <label><input type="radio" name="batchMode" value="price" onchange="toggleBatchMode()"> 💰 价格</label>
                </div>
            </div>

            <div id="batchAvailSection">
                <div class="field">
                    <label>可用房间数</label>
                    <input type="number" id="batchAvail" min="0" placeholder="输入可用房间数">
                </div>
            </div>

            <div id="batchPriceSection" style="display:none">
                <div class="field">
                    <label>价格调整方式</label>
                    <select id="batchPriceMode">
                        <option value="fixed">固定价格 ($)</option>
                        <option value="percent">百分比 (%)</option>
                        <option value="plus">加价 ($)</option>
                        <option value="minus">减价 ($)</option>
                    </select>
                </div>
                <div class="field">
                    <label>价格值</label>
                    <input type="number" id="batchPriceVal" step="0.01" placeholder="输入价格或百分比">
                </div>
                <div style="font-size:12px;color:#999;margin-top:-8px;margin-bottom:8px" id="batchPriceHint">示例：固定价格 — 输入70表示$70/晚</div>
            </div>

            <div style="display:flex;gap:8px;margin-top:16px">
                <button class="btn" onclick="doBatchSet()">✅ 应用</button>
                <button class="btn" style="background:#999;color:#fff" onclick="closeBatchModal()">取消</button>
            </div>
            <div id="batchResult" style="margin-top:12px;font-size:13px;color:#2e7d32"></div>
        </div>
    </div>
</div>

<!-- 价格编辑弹窗 -->
<div class="modal" id="priceEditModal">
    <div class="modal-box" style="max-width:400px">
        <div class="modal-hd">编辑价格 <button class="close" onclick="document.getElementById('priceEditModal').classList.remove('open')">×</button></div>
        <div class="modal-body">
            <input type="hidden" id="pe_room_id" value="0">
            <input type="hidden" id="pe_date" value="">
            <div class="field">
                <label>日期</label>
                <input id="pe_date_label" readonly style="background:#f5f5f5;color:#666">
            </div>
            <div class="field">
                <label>房型</label>
                <input id="pe_room_label" readonly style="background:#f5f5f5;color:#666">
            </div>
            <div class="field">
                <label>基础价 ($)</label>
                <input id="pe_base_price" readonly style="background:#f5f5f5;color:#666">
            </div>
            <div class="field">
                <label>新价格 ($) *</label>
                <input type="number" id="pe_new_price" step="0.01" min="0" placeholder="输入价格">
            </div>
            <div style="display:flex;gap:8px;margin-top:16px">
                <button class="btn" onclick="saveDayPrice()" style="padding:10px 36px;font-size:14px">💾 保存</button>
                <button class="btn" onclick="document.getElementById('priceEditModal').classList.remove('open')" style="background:#999;color:#fff;margin-left:8px">取消</button>
            </div>
            <div id="priceEditResult" style="margin-top:12px;font-size:13px;color:#2e7d32"></div>
        </div>
    </div>
</div>

<script>
/**
 * 显示单日详情弹窗
 */
function showDayDetail(dateStr, roomId, roomName, totalQty) {
    var el = document.getElementById('dayDetail');
    el.innerHTML = '<div style="text-align:center;padding:40px;color:#999">查询中...</div>';
    document.getElementById('dayModal').classList.add('open');

    // 并行获取预订数据和可用数设置
    Promise.all([
        fetch('/api/booking.php?action=check&date=' + dateStr).then(function(r){ return r.json(); }),
        fetch('/api/availability.php?action=get&room_id=' + roomId + '&date=' + dateStr).then(function(r){ return r.json(); })
    ]).then(function(results) {
        var d = results[0]; // booking check data
        var a = results[1]; // availability data

        // 提取预订数据
        var bookedCount = 0;
        if (d.ok && d.data && d.data.rooms) {
            var room = d.data.rooms.find(function(r){ return r.id == roomId; });
            if (room) {
                bookedCount = room.quantity - room.available;
            }
        }

        // 提取可用数设置
        var manualAvail = null;
        var isLocked = 0;
        var availNotes = '';
        if (a.ok && a.data) {
            if (a.data.available_rooms !== undefined && a.data.available_rooms !== null) {
                // 检查是否真的是手动设置（有 id 说明是数据库记录）
                if (a.data.id) {
                    manualAvail = a.data.available_rooms;
                }
            }
            isLocked = a.data.is_locked || 0;
            availNotes = a.data.notes || '';
        }

        // 最终可用数
        var finalAvailQty = (manualAvail !== null) ? Math.min(totalQty, manualAvail) : totalQty;
        var effectiveAvail = Math.max(0, finalAvailQty - bookedCount);

        var lockBtnText = isLocked ? '🔓 解锁' : '🔒 锁定';
        var lockStatusText = isLocked ? '<span style="color:#c62828;font-weight:600">已锁定 🔒</span>' : '<span style="color:#2e7d32">正常</span>';

        el.innerHTML =
            '<h3 style="font-size:16px;color:#1a2c3e;margin-bottom:4px">' + dateStr + '</h3>' +
            '<p style="color:#666;margin-bottom:16px">' + roomName + ' | ' + lockStatusText + '</p>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">' +
            '<div style="background:#e8f5e9;padding:20px;border-radius:8px;text-align:center">' +
            '<div style="font-size:32px;font-weight:700;color:#2e7d32">' + effectiveAvail + '</div>' +
            '<div style="font-size:13px;color:#666;margin-top:4px">可预订</div></div>' +
            '<div style="background:#ffebee;padding:20px;border-radius:8px;text-align:center">' +
            '<div style="font-size:32px;font-weight:700;color:#c62828">' + bookedCount + '</div>' +
            '<div style="font-size:13px;color:#666;margin-top:4px">已订</div></div></div>' +
            '<div style="font-size:12px;color:#999;text-align:center;margin-bottom:16px">总房量: ' + totalQty + '间' +
            (manualAvail !== null ? ' | 手动设置: ' + manualAvail + '间' : '') + '</div>' +

            // 编辑区域
            '<div style="border-top:2px solid #eee;padding-top:16px">' +
            '<h4 style="font-size:14px;color:#1a2c3e;margin-bottom:12px">✏️ 修改可用数</h4>' +
            '<div style="display:flex;gap:8px;align-items:center;margin-bottom:12px">' +
            '<input type="number" id="editAvailInput" min="0" max="' + totalQty + '" placeholder="可用数" style="flex:1;padding:8px 12px;border:1px solid #e0e0e0;border-radius:4px;font-size:13px"' +
            ' value="' + (manualAvail !== null ? manualAvail : totalQty) + '">' +
            '<span style="font-size:12px;color:#999">/ ' + totalQty + '</span>' +
            '<button class="btn-sm btn-edit" onclick="saveDayAvail(' + roomId + ',\'' + dateStr + '\',' + totalQty + ')">保存</button>' +
            '</div>' +
            '<div style="display:flex;gap:8px">' +
            '<button class="btn-sm" style="background:#e8eaf6;color:#283593;border:none;padding:6px 14px;border-radius:4px;cursor:pointer;font-size:12px" onclick="toggleLock(' + roomId + ',\'' + dateStr + '\')">' + lockBtnText + '</button>' +
            '<button class="btn-sm" style="background:#fce4ec;color:#c62828;border:none;padding:6px 14px;border-radius:4px;cursor:pointer;font-size:12px" onclick="clearDayAvail(' + roomId + ',\'' + dateStr + '\')">🗑️ 清除设置</button>' +
            '</div></div>';
    })
    ['catch'](function(){
        el.innerHTML = '<div style="text-align:center;padding:40px;color:#999">查询失败</div>';
    });
}

/**
 * 保存单日可用数
 */
function saveDayAvail(roomId, dateStr, totalQty) {
    var input = document.getElementById('editAvailInput');
    var val = parseInt(input.value);
    if (isNaN(val) || val < 0) { alert('请输入有效的可用数'); return; }
    if (val > totalQty) {
        if (!confirm('可用数(' + val + ')超过默认总房量(' + totalQty + ')，是否继续？')) return;
    }

    var f = new FormData();
    f.append('room_id', roomId);
    f.append('date', dateStr);
    f.append('available_rooms', val);

    fetch('/api/availability.php?action=set', {method:'POST', body:f})
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) {
                location.reload();
            } else {
                alert('保存失败：' + (d.error || '未知错误'));
            }
        })
        ['catch'](function(){ alert('请求失败'); });
}

/**
 * 清除单日手动设置
 */
function clearDayAvail(roomId, dateStr) {
    if (!confirm('确定清除该日的手动设置？')) return;

    var f = new FormData();
    // 设置为默认数量（通过设置-1让后端重置？或者直接删除记录）
    // 简单方案：设置与 room 默认数量相同，这样效果相当于清除
    fetch('/api/booking.php?action=check&date=' + dateStr)
        .then(function(r){ return r.json(); })
        .then(function(d){
            var totalQty = 0;
            if (d.ok && d.data && d.data.rooms) {
                var room = d.data.rooms.find(function(rr){ return rr.id == roomId; });
                if (room) totalQty = room.quantity;
            }
            // 设置为总房量，效果等于清除手动设置
            var f2 = new FormData();
            f2.append('room_id', roomId);
            f2.append('date', dateStr);
            f2.append('available_rooms', totalQty);
            return fetch('/api/availability.php?action=set', {method:'POST', body:f2});
        })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) location.reload();
            else alert('清除失败');
        })
        ['catch'](function(){ alert('请求失败'); });
}

/**
 * 锁定/解锁
 */
function toggleLock(roomId, dateStr) {
    var f = new FormData();
    f.append('room_id', roomId);
    f.append('date', dateStr);

    fetch('/api/availability.php?action=toggle_lock', {method:'POST', body:f})
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) {
                location.reload();
            } else {
                alert('操作失败：' + (d.error || '未知错误'));
            }
        })
        ['catch'](function(){ alert('请求失败'); });
}

/**
 * 显示批量修改弹窗
 */
function showBatchModal() {
    document.getElementById('batchModal').classList.add('open');
    document.getElementById('batchResult').innerHTML = '';
}

function closeBatchModal() {
    document.getElementById('batchModal').classList.remove('open');
}

/**
 * 执行批量修改
 */
function toggleBatchMode() {
    var mode=document.querySelector('input[name=batchMode]:checked').value;
    document.getElementById('batchAvailSection').style.display=mode==='availability'?'':'none';
    document.getElementById('batchPriceSection').style.display=mode==='price'?'':'none';
}
function doBatchSet() {
    var mode=document.querySelector('input[name=batchMode]:checked').value;
    var roomId=parseInt(document.getElementById('batchRoomId').value);
    var dateFrom=document.getElementById('batchDateFrom').value;
    var dateTo=document.getElementById('batchDateTo').value;
    if(!dateFrom||!dateTo){alert('请选择日期范围');return;}
    if(roomId===0){alert('请选择具体房型');return;}
    var el=document.getElementById('batchResult');el.style.color='#2e7d32';
    if(mode==='availability'){
        var avail=document.getElementById('batchAvail').value;
        if(avail===''||parseInt(avail)<0){alert('请输入有效的可用房间数');return;}
        var f=new FormData();f.append('room_id',roomId);f.append('date_from',dateFrom);f.append('date_to',dateTo);
        f.append('available_rooms',avail);f.append('action','set_range');
        el.innerHTML='处理中...';document.querySelector('#batchModal .btn').disabled=true;
        fetch('/api/availability.php',{method:'POST',body:f}).then(function(r){return r.json();}).then(function(d){
            if(d.ok){el.innerHTML='✔ 已应用，即将刷新...';setTimeout(function(){location.reload();},1000);}
            else{el.innerHTML='✖ 失败：'+(d.error||'');el.style.color='#c62828';document.querySelector('#batchModal .btn').disabled=false;}
        })['catch'](function(){el.innerHTML='✖ 网络错误';el.style.color='#c62828';document.querySelector('#batchModal .btn').disabled=false;});
    }else{
        var priceMode=document.getElementById('batchPriceMode').value;
        var priceVal=document.getElementById('batchPriceVal').value;
        if(priceVal===''||parseFloat(priceVal)<0){alert('请输入有效的价格');return;}
        el.innerHTML='处理中...';document.querySelector('#batchModal .btn').disabled=true;
        var ruleName='批量调价';var overrideVal='';var modVal=priceVal;
        if(priceMode==='fixed'){overrideVal=priceVal;modVal='';}
        var f=new FormData();f.append('action','save');f.append('room_id',roomId);
        f.append('name',ruleName+' '+dateFrom);f.append('rule_type','promotion');
        f.append('start_date',dateFrom);f.append('end_date',dateTo);
        f.append('price_override',overrideVal);f.append('price_modifier',modVal);f.append('priority','999');
        fetch('/api/pricing.php',{method:'POST',body:f}).then(function(r){return r.json();}).then(function(d){
            if(d.ok){el.innerHTML='✔ 价格已更新，即将刷新...';setTimeout(function(){location.reload();},1000);}
            else{el.innerHTML='✖ 失败：'+(d.error||'');el.style.color='#c62828';document.querySelector('#batchModal .btn').disabled=false;}
        })['catch'](function(){el.innerHTML='✖ 网络错误';el.style.color='#c62828';document.querySelector('#batchModal .btn').disabled=false;});
    }
}

/* ────────── 价格编辑功能 ────────── */

/**
 * 打开价格编辑弹窗
 */
function editDayPrice(roomId, day, dateStr, basePrice) {
    // 查找房型名称
    var roomName = '';
    var rows = document.querySelectorAll('tr[data-room-id]');
    for (var i = 0; i < rows.length; i++) {
        if (parseInt(rows[i].getAttribute('data-room-id')) === roomId) {
            roomName = rows[i].cells[0].textContent.trim();
            break;
        }
    }
    
    var month = String(<?= $calMonth ?>).padStart(2, '0');
    var year = <?= $calYear ?>;
    var fullDate = year + '-' + month + '-' + String(day).padStart(2, '0');
    
    document.getElementById('pe_room_id').value = roomId;
    document.getElementById('pe_date').value = fullDate;
    document.getElementById('pe_date_label').value = fullDate;
    document.getElementById('pe_room_label').value = roomName;
    document.getElementById('pe_base_price').value = '$' + basePrice;
    document.getElementById('pe_new_price').value = '';
    document.getElementById('priceEditResult').innerHTML = '';
    
    // 尝试预填当前价格
    var parentRow = document.querySelector('tr[data-room-id="' + roomId + '"]');
    if (parentRow) {
        var priceRow = parentRow.nextElementSibling;
        if (priceRow && priceRow.classList.contains('price-row')) {
            var priceCells = priceRow.querySelectorAll('.price-cell');
            if (priceCells.length >= day) {
                var currentText = priceCells[day - 1].textContent.trim();
                if (currentText && currentText !== '-') {
                    document.getElementById('pe_new_price').value = currentText.replace('$', '');
                }
            }
        }
    }
    
    document.getElementById('priceEditModal').classList.add('open');
}

/**
 * 保存单日价格（通过新建一个临时规则实现）
 * 创建一条针对该日该房型的固定价格规则
 */
function saveDayPrice() {
    var roomId = parseInt(document.getElementById('pe_room_id').value);
    var dateStr = document.getElementById('pe_date').value;
    var newPrice = parseFloat(document.getElementById('pe_new_price').value);
    
    if (!roomId || !dateStr) { alert('数据不完整'); return; }
    if (isNaN(newPrice) || newPrice <= 0) { alert('请输入有效的价格'); return; }
    
    var f = new FormData();
    f.append('action', 'save');
    f.append('id', '0');
    f.append('room_id', roomId);
    f.append('name', dateStr + ' 手动调价');
    f.append('rule_type', 'promotion');
    f.append('start_date', dateStr);
    f.append('end_date', dateStr);
    f.append('price_override', newPrice);
    f.append('price_modifier', '');
    f.append('priority', '999');
    
    document.getElementById('priceEditResult').innerHTML = '保存中...';
    document.getElementById('priceEditResult').style.color = '#999';
    
    var btn = event.target;
    btn.textContent = '保存中...';
    btn.disabled = true;
    
    fetch('/api/pricing.php', { method: 'POST', body: f })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok) {
                document.getElementById('priceEditResult').innerHTML = '✅ 价格已更新，正在刷新...';
                document.getElementById('priceEditResult').style.color = '#2e7d32';
                setTimeout(function(){ location.reload(); }, 800);
            } else {
                document.getElementById('priceEditResult').innerHTML = '❌ ' + (d.error || '保存失败');
                document.getElementById('priceEditResult').style.color = '#c62828';
            }
        })
        ['catch'](function() {
            document.getElementById('priceEditResult').innerHTML = '❌ 请求失败';
            document.getElementById('priceEditResult').style.color = '#c62828';
        })
        .finally(function() {
            btn.textContent = '💾 保存';
            btn.disabled = false;
        });
}
</script>
