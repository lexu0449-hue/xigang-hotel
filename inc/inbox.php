<?php
/**
 * 消息收件箱 — Messages & Contact form submissions
 */
?>
<div class="topbar"><h1>💬 消息收件箱</h1></div>

<div style="display:flex;gap:12px;margin-bottom:16px">
    <button class="btn" onclick="loadInbox('messages')" id="tabMsgs" style="background:#1a2c3e;color:#fff">📨 客户消息</button>
    <button class="btn" onclick="loadInbox('contacts')" id="tabContacts">📝 联系表单</button>
</div>

<div id="inboxContent"><div style="text-align:center;padding:60px;color:#999">加载中...</div></div>

<div class="modal" id="replyModal">
    <div class="modal-box" style="max-width:550px">
        <div class="modal-hd">回复消息 <button class="close" onclick="closeReply()">×</button></div>
        <div class="modal-body">
            <div style="background:#f5f5f5;padding:12px;border-radius:6px;margin-bottom:16px;font-size:13px" id="replyContext"></div>
            <div class="field">
                <label>选择模板 <small style="color:#999;font-weight:400">（点击插入）</small></label>
                <div id="templateSelector" style="margin-bottom:10px;display:flex;flex-wrap:wrap;gap:6px"></div>
            </div>
            <div class="field">
                <label>回复内容</label>
                <textarea id="replyContent" style="height:120px;width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:13px;font-family:inherit" placeholder="输入回复内容..."></textarea>
            </div>
            <input type="hidden" id="replyBookingId">
            <button class="btn" onclick="sendReply()" style="padding:10px 30px">发送回复</button>
            &nbsp;
            <button class="btn" style="background:#f0f0f0;color:#666" onclick="showTemplateManager()">管理模板</button>
        </div>
    </div>
</div>

<!-- Contact Detail Modal -->
<div class="modal" id="contactDetailModal">
    <div class="modal-box" style="max-width:500px">
        <div class="modal-hd">📝 联系表单详情 <button class="close" onclick="closeContactDetail()">×</button></div>
        <div class="modal-body" id="contactDetailBody">
            <div style="margin-bottom:16px">
                <div style="font-size:12px;color:#999;margin-bottom:4px">发送人</div>
                <div id="cdName" style="font-size:15px;font-weight:600"></div>
            </div>
            <div style="margin-bottom:16px">
                <div style="font-size:12px;color:#999;margin-bottom:4px">联系方式</div>
                <div id="cdPhone" style="font-size:14px"></div>
            </div>
            <div style="margin-bottom:16px">
                <div style="font-size:12px;color:#999;margin-bottom:4px">内容</div>
                <div id="cdMessage" style="font-size:14px;line-height:1.6;background:#f5f5f5;padding:12px;border-radius:6px;white-space:pre-wrap"></div>
            </div>
            <div style="margin-bottom:16px">
                <div style="font-size:12px;color:#999;margin-bottom:4px">提交时间</div>
                <div id="cdTime" style="font-size:13px;color:#666"></div>
            </div>
        </div>
    </div>
</div>

<!-- Template Manager Modal -->
<div class="modal" id="templateManagerModal">
    <div class="modal-box" style="max-width:600px">
        <div class="modal-hd">📝 管理消息模板 <button class="close" onclick="closeTemplateManager()">×</button></div>
        <div class="modal-body">
            <div style="margin-bottom:16px;padding:16px;background:#f9f9f9;border-radius:8px">
                <h4 style="margin-bottom:8px;font-size:14px">添加新模板</h4>
                <div class="field"><input type="text" id="tmplTitle" placeholder="模板名称" style="width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px"></div>
                <div class="field"><textarea id="tmplContent" placeholder="模板内容..." style="width:100%;height:100px;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;font-family:inherit"></textarea></div>
                <button class="btn" onclick="addTemplate()" style="padding:8px 20px">＋ 添加</button>
            </div>
            <div id="templateList"><div style="text-align:center;padding:20px;color:#999">加载中...</div></div>
        </div>
    </div>
</div>

<script>
function loadInbox(tab) {
    // Update tab styles
    var msgsBtn = document.getElementById('tabMsgs');
    var contBtn = document.getElementById('tabContacts');
    if (tab === 'messages') {
        msgsBtn.style.background = '#1a2c3e'; msgsBtn.style.color = '#fff';
        contBtn.style.background = '#c8a96e'; contBtn.style.color = '#1a2c3e';
    } else {
        contBtn.style.background = '#1a2c3e'; contBtn.style.color = '#fff';
        msgsBtn.style.background = '#c8a96e'; msgsBtn.style.color = '#1a2c3e';
    }

    var el = document.getElementById('inboxContent');
    el.innerHTML = '<div style="text-align:center;padding:60px;color:#999">加载中...</div>';
    
    fetch('/api/inbox.php?action=' + tab)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.ok) { el.innerHTML = '<div style="text-align:center;padding:60px;color:#999">' + (d.error||'加载失败') + '</div>'; return; }
            if (!d.data || !d.data.length) {
                el.innerHTML = '<div style="text-align:center;padding:80px;color:#999;font-size:14px">' +
                    '<div style="font-size:48px;margin-bottom:16px">📭</div><p>暂无消息</p></div>';
                return;
            }
            var html = '<table><tr><th>时间</th><th>发送人</th><th>联系方式</th><th>内容</th><th>关联订单</th><th>状态</th><th>操作</th></tr>';
            d.data.forEach(function(m) {
                var time = (m.created_at || '').split('.')[0].replace('T',' ');
                var name = m.sender_name || m.name || m.guest_name || '游客';
                var phone = m.guest_phone || m.phone || m.sender_phone || '-';
                var content = (m.content || m.message || '');
                var shortContent = content.length > 60 ? content.substring(0, 60) + '...' : content;
                var bookingRef = m.booking_no || m.booking_id || '-';
                var isUnread = !m.is_read;
                var rowBg = isUnread ? 'background:#fff8e1;font-weight:600' : '';
                var statusLabel = isUnread ? '<span style="color:#e65100">● 未读</span>' : '已读';
                var actionBtn = '';
                if (tab === 'contacts') {
                    actionBtn = '<button class="btn-sm btn-edit" onclick="viewContact(' + m.id + ',\'' + (name || '').replace(/'/g,"\\'") + '\')">查看</button>';
                } else if (tab === 'messages' && m.sender_type !== 'admin') {
                    var safeName = (name || '').replace(/'/g, "\\'");
                    var safeContent = (content || '').substring(0, 40).replace(/'/g, "\\'").replace(/</g, '&lt;');
                    actionBtn = '<button class="btn-sm btn-edit" onclick="openReply(' + (m.booking_id || 0) + ',\'' + safeName + '\',' + m.id + ')">回复</button>';
                }
                html += '<tr style="' + rowBg + '">' +
                    '<td style="font-size:12px;white-space:nowrap">' + time + '</td>' +
                    '<td style="font-weight:' + (isUnread ? '600' : '400') + '">' + name + '</td>' +
                    '<td style="font-size:12px">' + phone + '</td>' +
                    '<td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px" title="' + content.replace(/"/g,'&quot;') + '">' + shortContent + '</td>' +
                    '<td style="font-size:12px">' + bookingRef + '</td>' +
                    '<td>' + statusLabel + '</td>' +
                    '<td class="actions">' + actionBtn + '</td></tr>';
            });
            el.innerHTML = html + '</table>';
        })
        ['catch'](function() {
            el.innerHTML = '<div style="text-align:center;padding:60px;color:#999">加载失败，请检查网络</div>';
        });
}

function openReply(bookingId, name, msgId) {
    document.getElementById('replyBookingId').value = bookingId || 0;
    document.getElementById('replyContext').innerHTML = '回复给: <strong>' + name + '</strong>';
    document.getElementById('replyContent').value = '';
    document.getElementById('replyModal').classList.add('open');
    
    // Mark as read
    if (msgId) {
        var f = new FormData();
        f.append('id', msgId);
        fetch('/api/inbox.php?action=mark_read', { method: 'POST', body: f })['catch'](function(){});
    }
}

function closeReply() {
    document.getElementById('replyModal').classList.remove('open');
}

function sendReply() {
    var content = document.getElementById('replyContent').value.trim();
    if (!content) { alert('请输入回复内容'); return; }
    
    var f = new FormData();
    f.append('booking_id', document.getElementById('replyBookingId').value);
    f.append('content', content);
    f.append('sender_name', '管理员');
    
    var btn = event.target;
    btn.textContent = '发送中...';
    btn.disabled = true;
    
    fetch('/api/inbox.php?action=send', { method: 'POST', body: f })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok) {
                alert('✅ 回复已发送');
                closeReply();
                loadInbox('messages');
            } else {
                alert('发送失败: ' + (d.error || '未知错误'));
            }
        })
        ['catch'](function() { alert('网络错误'); })
        .finally(function() {
            btn.textContent = '发送回复';
            btn.disabled = false;
        });
}

// ===== 联系表单查看 =====
function viewContact(id, name) {
    document.getElementById('contactDetailModal').classList.add('open');
    document.getElementById('cdName').textContent = name;
    document.getElementById('cdPhone').textContent = '加载中...';
    document.getElementById('cdMessage').textContent = '加载中...';
    document.getElementById('cdTime').textContent = '';
    
    fetch('/api/inbox.php?action=contacts')
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.ok || !d.data) return;
            var c = d.data.find(function(x){ return x.id == id; });
            if (!c) return;
            document.getElementById('cdName').textContent = c.name || name;
            document.getElementById('cdPhone').textContent = c.phone || c.email || '-';
            document.getElementById('cdMessage').textContent = c.message || c.content || '(无内容)';
            document.getElementById('cdTime').textContent = (c.created_at || '').split('.')[0].replace('T',' ');
        })
        ['catch'](function(){});
}

function closeContactDetail() {
    document.getElementById('contactDetailModal').classList.remove('open');
}

// ===== 模板管理 =====
var templates = [];

function loadTemplates() {
    fetch('/api/inbox.php?action=templates')
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.ok || !d.data) return;
            templates = d.data;
            renderTemplateSelector();
            renderTemplateList();
        })
        ['catch'](function(){});
}

function renderTemplateSelector() {
    var el = document.getElementById('templateSelector');
    if (!el) return;
    if (!templates.length) {
        el.innerHTML = '<span style="font-size:12px;color:#999">暂无模板，可点击"管理模板"添加</span>';
        return;
    }
    el.innerHTML = templates.map(function(t) {
        return '<button class="btn-sm btn-edit" onclick="insertTemplate(' + t.id + ')" title="' + t.content.replace(/"/g,'&quot;') + '">' + t.title + '</button>';
    }).join('');
}

function insertTemplate(id) {
    var t = templates.find(function(x){ return x.id == id; });
    if (!t) return;
    var ta = document.getElementById('replyContent');
    ta.value = t.content;
    ta.focus();
}

function showTemplateManager() {
    document.getElementById('templateManagerModal').classList.add('open');
    renderTemplateList();
}

function closeTemplateManager() {
    document.getElementById('templateManagerModal').classList.remove('open');
}

function renderTemplateList() {
    var el = document.getElementById('templateList');
    if (!el) return;
    if (!templates.length) {
        el.innerHTML = '<div style="text-align:center;padding:20px;color:#999">暂无模板</div>';
        return;
    }
    el.innerHTML = templates.map(function(t) {
        return '<div style="padding:12px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:flex-start">' +
            '<div style="flex:1">' +
                '<strong style="font-size:14px">' + t.title + '</strong>' +
                '<div style="font-size:12px;color:#666;margin-top:4px">' + t.content.substring(0, 80) + (t.content.length > 80 ? '...' : '') + '</div>' +
            '</div>' +
            '<button class="btn-sm" style="background:#fce4ec;color:#c62828;flex-shrink:0;margin-left:12px" onclick="deleteTemplate(' + t.id + ')">删除</button>' +
        '</div>';
    }).join('');
}

function addTemplate() {
    var title = document.getElementById('tmplTitle').value.trim();
    var content = document.getElementById('tmplContent').value.trim();
    if (!title || !content) { alert('请填写标题和内容'); return; }
    var f = new FormData();
    f.append('title', title);
    f.append('content', content);
    fetch('/api/inbox.php?action=template_add', { method: 'POST', body: f })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) {
                document.getElementById('tmplTitle').value = '';
                document.getElementById('tmplContent').value = '';
                loadTemplates();
            } else {
                alert(d.error || '添加失败');
            }
        })
        ['catch'](function(){ alert('网络错误'); });
}

function deleteTemplate(id) {
    if (!confirm('确定删除此模板？')) return;
    var f = new FormData();
    f.append('id', id);
    fetch('/api/inbox.php?action=template_delete', { method: 'POST', body: f })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) loadTemplates();
            else alert(d.error || '删除失败');
        })
        ['catch'](function(){ alert('网络错误'); });
}

// Load on page load
document.addEventListener('DOMContentLoaded', function() {
    loadInbox('messages');
    loadTemplates();
});
</script>
