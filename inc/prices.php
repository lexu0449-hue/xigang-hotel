<?php
/**
 * 活动/促销管理 — Activity & Promotion rules
 * 含：活动列表、新建活动弹窗、活动类型统计
 */
?>
<div class="topbar"><h1>🏷 活动/促销管理</h1></div>

<!-- ======== 工具栏 ======== -->
<div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;align-items:center">
    <button class="btn" onclick="showPriceForm()" style="background:#1a2c3e;color:#fff">＋ 新建活动</button>
    <button class="btn" onclick="loadPriceRules()">🔄 刷新</button>
    <span style="font-size:12px;color:#999;margin-left:8px">管理促销活动、季节性调价、限时优惠等</span>
</div>

<!-- ======== 统计卡片 ======== -->
<div id="priceSummary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px">
    <div class="stat-card"><div class="num" id="ruleCount">0</div><div class="label">活动总数</div></div>
    <div class="stat-card"><div class="num" id="seasonalCount">0</div><div class="label">季节性/节假日</div></div>
    <div class="stat-card"><div class="num" id="promoCount">0</div><div class="label">促销/限时优惠</div></div>
    <div class="stat-card"><div class="num" id="packageCount">0</div><div class="label">套餐/会员专享</div></div>
</div>

<!-- ======== 活动列表 ======== -->
<div id="priceRuleList" style="background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.04);min-height:200px">
    <div style="text-align:center;padding:80px;color:#999;font-size:14px">
        <div style="font-size:48px;margin-bottom:12px">🏷</div>
        <p>加载中...</p>
    </div>
</div>

<!-- ======== 新建/编辑活动弹窗 ======== -->
<div class="modal" id="priceFormModal">
    <div class="modal-box" style="max-width:550px">
        <div class="modal-hd">活动规则 <button class="close" onclick="closePriceForm()">×</button></div>
        <div class="modal-body">
            <input type="hidden" id="pf_id" value="0">
            
            <div class="field">
                <label>活动名称 *</label>
                <input id="pf_name" placeholder="如：春节调价、夏日促销、连住优惠">
            </div>
            
            <div class="field">
                <label>适用房型</label>
                <select id="pf_room_id"><option value="">加载中...</option></select>
            </div>
            
            <div class="field">
                <label>活动类型</label>
                <select id="pf_rule_type">
                    <option value="seasonal">🌤 季节性调价</option>
                    <option value="holiday">🎉 节假日</option>
                    <option value="promotion">🏷 促销活动</option>
                    <option value="flash_sale">⚡ 限时优惠</option>
                    <option value="package">🎁 套餐活动</option>
                    <option value="member_only">💎 会员专享</option>
                </select>
            </div>
            
            <div class="field">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div><label>开始日期 *</label><input type="date" id="pf_start"></div>
                    <div><label>结束日期 *</label><input type="date" id="pf_end"></div>
                </div>
            </div>

            <div style="background:#f5f7fa;border-radius:6px;padding:16px;margin-bottom:14px">
                <label style="font-size:13px;color:#666;display:block;margin-bottom:8px;font-weight:500">优惠方式</label>
                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                    <select id="pf_modifier_type" style="width:auto;padding:8px 12px;border:1px solid #ddd;border-radius:4px;font-size:13px">
                        <option value="percent">百分比 (%)</option>
                        <option value="fixed">固定价格 ($)</option>
                    </select>
                    <input type="number" id="pf_modifier" step="1" style="width:120px;padding:8px 12px;border:1px solid #ddd;border-radius:4px;font-size:13px" placeholder="如: 20">
                    <span style="font-size:12px;color:#999" id="modifierHint">正数涨价，负数降价。0=维持原价</span>
                </div>
            </div>

            <div class="field">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div><label>优惠价 ($)</label><input type="number" id="pf_override" step="0.01" placeholder="可选，优先于百分比"></div>
                    <div><label>优先级</label><input type="number" id="pf_priority" value="0" step="1" placeholder="越大越优先"></div>
                </div>
            </div>

            <div style="border-top:1px solid #eee;padding-top:16px;margin-top:8px">
                <button class="btn" onclick="savePriceRule()" style="padding:10px 36px;font-size:14px">💾 保存活动</button>
                <button class="btn" onclick="closePriceForm()" style="background:#999;color:#fff;margin-left:8px">取消</button>
            </div>
        </div>
    </div>
</div>

<script>
var allPriceRules = [];

// ==================== 活动列表 ====================
function loadPriceRules() {
    var el = document.getElementById('priceRuleList');
    el.innerHTML = '<div style="text-align:center;padding:80px;color:#999;font-size:14px"><div style="font-size:48px;margin-bottom:12px">⏳</div><p>加载中...</p></div>';
    
    fetch('/api/pricing.php?action=list')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.ok) {
                el.innerHTML = '<div style="text-align:center;padding:60px;color:#999">' + (d.error||'加载失败') + '</div>';
                return;
            }
            allPriceRules = d.data || [];
            
            var seasonalCount = 0, promoCount = 0, packageCount = 0;
            allPriceRules.forEach(function(r) {
                if (r.rule_type === 'seasonal' || r.rule_type === 'holiday') seasonalCount++;
                if (r.rule_type === 'promotion' || r.rule_type === 'flash_sale') promoCount++;
                if (r.rule_type === 'package' || r.rule_type === 'member_only') packageCount++;
            });
            document.getElementById('ruleCount').textContent = allPriceRules.length;
            document.getElementById('seasonalCount').textContent = seasonalCount;
            document.getElementById('promoCount').textContent = promoCount;
            document.getElementById('packageCount').textContent = packageCount;

            if (!allPriceRules.length) {
                el.innerHTML = '<div style="text-align:center;padding:80px;color:#999;font-size:14px">' +
                    '<div style="font-size:48px;margin-bottom:12px">🏷</div>' +
                    '<p>暂无活动</p>' +
                    '<p style="font-size:12px;margin-top:8px">点击上方「新建活动」开始设置</p></div>';
                return;
            }

            var typeLabels = {
                'seasonal': '🌤 季节性', 'holiday': '🎉 节假日',
                'promotion': '🏷 促销', 'flash_sale': '⚡ 限时优惠',
                'package': '🎁 套餐', 'member_only': '💎 会员专享', 'base': '📌 基础'
            };

            var html = '<table><thead><tr>' +
                '<th>活动名称</th><th>房型</th><th>类型</th>' +
                '<th>日期范围</th><th>优惠幅度</th><th>优先级</th><th>状态</th><th>操作</th></tr></thead><tbody>';

            allPriceRules.forEach(function(r) {
                var adjText = '';
                if (r.price_override) {
                    adjText = '<strong style="color:#1565c0">$' + r.price_override + '</strong> <span style="font-size:11px;color:#999">套餐价</span>';
                } else if (r.price_modifier) {
                    var val = parseFloat(r.price_modifier);
                    var color = val > 0 ? '#c62828' : (val < 0 ? '#2e7d32' : '#666');
                    var sign = val > 0 ? '+' : '';
                    adjText = '<strong style="color:' + color + '">' + sign + val + '%</strong>';
                } else {
                    adjText = '<span style="color:#999">-</span>';
                }

                var typeLabel = typeLabels[r.rule_type] || r.rule_type;
                var active = r.is_active == 1;

                html += '<tr>' +
                    '<td style="font-weight:600">' + r.name + '</td>' +
                    '<td>' + (r.room_name || '-') + '</td>' +
                    '<td>' + typeLabel + '</td>' +
                    '<td style="font-size:12px;white-space:nowrap">' + r.start_date + ' → ' + r.end_date + '</td>' +
                    '<td>' + adjText + '</td>' +
                    '<td style="text-align:center">' + (r.priority || 0) + '</td>' +
                    '<td>' + (active ? '<span style="color:#2e7d32">✅ 生效</span>' : '<span style="color:#999">停用</span>') + '</td>' +
                    '<td class="actions">' +
                    '<button class="btn-sm btn-edit" onclick="editRule(' + r.id + ')">编辑</button>' +
                    '<button class="btn-sm btn-del" onclick="deleteRule(' + r.id + ')">删除</button></td></tr>';
            });

            html += '</tbody></table>';
            el.innerHTML = html;
        })
        ['catch'](function() {
            el.innerHTML = '<div style="text-align:center;padding:60px;color:#999">加载失败，请刷新重试</div>';
        });
}

// ==================== 活动弹窗 ====================
function showPriceForm(data) {
    data = data || {};
    document.getElementById('pf_id').value = data.id || 0;
    document.getElementById('pf_name').value = data.name || '';
    document.getElementById('pf_start').value = data.start_date || '';
    document.getElementById('pf_end').value = data.end_date || '';
    document.getElementById('pf_rule_type').value = data.rule_type || 'seasonal';
    document.getElementById('pf_modifier').value = data.price_modifier || '';
    document.getElementById('pf_override').value = data.price_override || '';
    document.getElementById('pf_priority').value = data.priority || 0;
    
    if (data.room_id) {
        document.getElementById('pf_room_id').value = data.room_id;
    }
    
    document.getElementById('priceFormModal').classList.add('open');
}

function closePriceForm() {
    document.getElementById('priceFormModal').classList.remove('open');
}

function editRule(id) {
    var r = allPriceRules.find(function(x) { return x.id == id; });
    if (r) {
        showPriceForm(r);
        document.getElementById('pf_room_id').value = r.room_id;
    }
}

function savePriceRule() {
    var name = document.getElementById('pf_name').value.trim();
    var roomId = document.getElementById('pf_room_id').value;
    var start = document.getElementById('pf_start').value;
    var end = document.getElementById('pf_end').value;

    if (!name) { alert('请输入活动名称'); return; }
    if (roomId === '' && document.getElementById('pf_id').value == 0) { alert('请选择适用房型'); return; }
    if (!start || !end) { alert('请选择日期范围'); return; }
    if (end < start) { alert('结束日期不能早于开始日期'); return; }

    var f = new FormData();
    f.append('action', 'save');
    f.append('id', document.getElementById('pf_id').value);
    f.append('room_id', roomId);
    f.append('name', name);
    f.append('rule_type', document.getElementById('pf_rule_type').value);
    f.append('start_date', start);
    f.append('end_date', end);
    f.append('price_modifier', document.getElementById('pf_modifier').value || '');
    f.append('price_override', document.getElementById('pf_override').value || '');
    f.append('priority', document.getElementById('pf_priority').value);

    var btn = event.target;
    var originalText = btn.textContent;
    btn.textContent = '保存中...';
    btn.disabled = true;

    fetch('/api/pricing.php', { method: 'POST', body: f })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok) {
                closePriceForm();
                loadPriceRules();
            } else {
                alert('保存失败: ' + (d.error || '未知错误'));
            }
        })
        ['catch'](function() { alert('网络错误'); })
        .finally(function() {
            btn.textContent = originalText;
            btn.disabled = false;
        });
}

function deleteRule(id) {
    if (!confirm('确定要删除此活动吗？\n（若为批量创建的规则，将删除所有同名活动）')) return;
    var f = new FormData();
    f.append('action', 'delete');
    f.append('id', id);
    fetch('/api/pricing.php', { method: 'POST', body: f })
        .then(function(r) { return r.json(); })
        .then(function(d) { if (d.ok) { loadPriceRules(); } else alert(d.error); });
}

// ==================== 初始化 ====================
// 加载房型选择器
fetch('/api/pricing.php?action=rooms')
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.ok && d.data) {
            var sel = document.getElementById('pf_room_id');
            sel.innerHTML = '<option value="">请选择房型</option>' +
                '<option value="0">🏨 全部房型</option>';
            d.data.forEach(function(r) {
                sel.innerHTML += '<option value="' + r.id + '">' + r.hn + ' - ' + r.name + ' (原价$' + r.price + ')</option>';
            });
        }
    });

document.addEventListener('DOMContentLoaded', function() {
    loadPriceRules();
});
</script>
