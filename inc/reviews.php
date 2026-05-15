<?php
/**
 * 评价管理 — 查看/回复/展示控制
 */
?>
<div class="topbar"><h1>⭐ 评价管理</h1></div>

<div id="reviewStats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:20px"></div>

<div style="display:flex;gap:12px;margin-bottom:16px">
    <button class="btn" onclick="loadReviews()" style="background:#1a2c3e;color:#fff">🔄 刷新</button>
</div>

<div id="reviewList" style="background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.04);min-height:200px">
    <div style="text-align:center;padding:80px;color:#999;font-size:14px">
        <div style="font-size:48px;margin-bottom:12px">⭐</div>
        <p>加载中...</p>
    </div>
</div>

<div class="modal" id="replyReviewModal">
    <div class="modal-box" style="max-width:550px">
        <div class="modal-hd">回复评价 <button class="close" onclick="closeReviewReply()">×</button></div>
        <div class="modal-body">
            <div style="background:#f5f5f5;padding:16px;border-radius:6px;margin-bottom:16px" id="reviewContentQuote"></div>
            <div class="field">
                <label>回复内容</label>
                <textarea id="reviewReplyContent" style="height:120px;width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:13px"></textarea>
            </div>
            <input type="hidden" id="reviewReplyId">
            <button class="btn" onclick="submitReviewReply()">发送回复</button>
        </div>
    </div>
</div>

<script>
function loadReviews() {
    // Load stats
    fetch('/api/reviews.php?action=stat')
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok && d.data) {
                var html = '';
                d.data.forEach(function(s){
                    html += '<div class="stat-card"><div class="num">' + s.avg_score + '</div><div class="label">' + s.name + ' (' + s.cnt + '条)</div></div>';
                });
                document.getElementById('reviewStats').innerHTML = html;
            }
        });

    // Load reviews
    var el = document.getElementById('reviewList');
    el.innerHTML = '<div style="text-align:center;padding:80px;color:#999;font-size:14px"><div style="font-size:48px;margin-bottom:12px">⏳</div><p>加载中...</p></div>';

    fetch('/api/reviews.php?action=list')
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.ok || !d.data) {
                el.innerHTML = '<div style="text-align:center;padding:60px;color:#999">' + (d.error||'加载失败') + '</div>';
                return;
            }
            if (!d.data.length) {
                el.innerHTML = '<div style="text-align:center;padding:80px;color:#999;font-size:14px">' +
                    '<div style="font-size:48px;margin-bottom:12px">📝</div><p>暂无评价</p></div>';
                return;
            }
            var html = '<table><thead><tr>' +
                '<th>时间</th><th>酒店</th><th>客人</th><th>评分</th><th>评价内容</th><th>回复</th><th>展示</th><th>操作</th></tr></thead><tbody>';
            d.data.forEach(function(r){
                var stars = '';
                for (var i = 0; i < 5; i++) stars += (i < r.score ? '⭐' : '☆');
                var replyText = r.reply ? r.reply.substring(0, 30) + (r.reply.length > 30 ? '...' : '') : '<span style="color:#999">未回复</span>';
                var showStatus = r.is_published ? '<span style="color:#2e7d32">✅ 展示</span>' : '<span style="color:#999">❌ 隐藏</span>';
                html += '<tr>' +
                    '<td style="font-size:12px;white-space:nowrap">' + (r.created_at||'').split(' ')[0] + '</td>' +
                    '<td>' + (r.hotel_name||'') + '</td>' +
                    '<td>' + (r.guest_name||r.user_name||'匿名') + '</td>' +
                    '<td>' + stars + '</td>' +
                    '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + (r.content||'').replace(/"/g,'&quot;') + '">' + (r.content||'') + '</td>' +
                    '<td style="font-size:12px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + replyText + '</td>' +
                    '<td>' + showStatus + '</td>' +
                    '<td class="actions">' +
                    '<button class="btn-sm btn-edit" onclick="openReviewReply(' + r.id + ',\'' + (r.guest_name||'匿名').replace(/'/g,"\\'") + '\',\'' + (r.content||'').substring(0,50).replace(/'/g,"\\'").replace(/\n/g,' ') + '\')">回复</button>' +
                    '<button class="btn-sm ' + (r.is_published ? 'btn-cancel' : 'btn-confirm') + '" onclick="toggleReview(' + r.id + ')">' + (r.is_published ? '隐藏' : '展示') + '</button></td></tr>';
            });
            el.innerHTML = html + '</tbody></table>';
        })
        ['catch'](function(){ el.innerHTML = '<div style="text-align:center;padding:60px;color:#999">加载失败</div>'; });
}

function openReviewReply(id, name, content) {
    document.getElementById('reviewReplyId').value = id;
    document.getElementById('reviewContentQuote').innerHTML = '<strong>' + name + '</strong>: ' + content;
    document.getElementById('reviewReplyContent').value = '';
    document.getElementById('replyReviewModal').classList.add('open');
}

function closeReviewReply() {
    document.getElementById('replyReviewModal').classList.remove('open');
}

function submitReviewReply() {
    var id = document.getElementById('reviewReplyId').value;
    var reply = document.getElementById('reviewReplyContent').value.trim();
    if (!reply) { alert('请输入回复内容'); return; }
    var f = new FormData();
    f.append('action', 'reply');
    f.append('id', id);
    f.append('reply', reply);
    fetch('/api/reviews.php', {method:'POST', body:f})
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) { closeReviewReply(); loadReviews(); }
            else { alert(d.error||'失败'); }
        });
}

function toggleReview(id) {
    var f = new FormData();
    f.append('action', 'toggle');
    f.append('id', id);
    fetch('/api/reviews.php', {method:'POST', body:f})
        .then(function(r){ return r.json(); })
        .then(function(d){ if (d.ok) loadReviews(); });
}

document.addEventListener('DOMContentLoaded', loadReviews);
</script>
