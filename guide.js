// 西港攻略 — 动态加载
var currentCategory = '';

function loadGuides(category) {
  currentCategory = category || '';
  var url = 'data/guides.json';
  if (category) url += '&category=' + encodeURIComponent(category);
  var grid = document.getElementById('guideGrid');
  if (!grid) return;
  grid.innerHTML = '<div style="text-align:center;padding:80px;color:#999;grid-column:1/-1">\u52a0\u8f7d\u4e2d...</div>';
  fetch(url).then(function(r){ return r.json(); }).then(function(d){
    if (!d.ok || !d.data || !d.data.length) {
      grid.innerHTML = '<div style="text-align:center;padding:80px;color:#999;grid-column:1/-1">\u6682\u65e0\u653b\u7565</div>';
      return;
    }
    var cm = {'\u7f8e\u98df':'\ud83c\udf5c','\u666f\u70b9':'\ud83c\udfd6\ufe0f','\u907f\u5751':'\u26a0\ufe0f','\u4ea4\u901a':'\ud83d\ude8c','\u7b7e\u8bc1':'\ud83d\udcc4'};
    grid.innerHTML = d.data.map(function(g){
      var ic = cm[g.category] || '\ud83d\udcdd';
      return '<div class="guide-card"><div class="card-img"><img src="' + (g.cover_image || 'images/crown_01.jpg') + '" alt="' + g.title + '"><span class="card-tag">' + ic + ' ' + (g.category || '\u653b\u7565') + '</span></div><div class="card-body"><h3>' + g.title + '</h3><p>' + (g.summary || '') + '</p><a href="#" class="read-more">\u9605\u8bfb\u5168\u6587 \u2192</a></div></div>';
    }).join('');
  })['catch'](function(){
    var g = document.getElementById('guideGrid');
    if (g) g.innerHTML = '<div style="text-align:center;padding:80px;color:#999">\u52a0\u8f7d\u5931\u8d25</div>';
  });
}

document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.cat-nav a').forEach(function(link){
    link.addEventListener('click', function(e){
      e.preventDefault();
      document.querySelectorAll('.cat-nav a').forEach(function(a){ a.classList.remove('active'); });
      this.classList.add('active');
      var t = this.textContent.trim().replace(/[^\u4e00-\u9fa5]/g, '').trim();
      loadGuides(t === '\u5168\u90e8' ? '' : t);
    });
  });
  loadGuides('');
});
