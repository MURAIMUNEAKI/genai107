/* ===== gennai2 Markdown → HTML レンダラー ===== */

/**
 * renderMarkdown(text) → HTML string
 * — GFM サブセット（テーブル、コードブロック、リスト、見出し、リンク等）
 */
function renderMarkdown(md) {
  if (!md) return '';

  // 改行を正規化
  md = md.replace(/\r\n/g, '\n');

  // コードブロック ```lang\n...\n``` を先に抽出
  var codeBlocks = [];
  md = md.replace(/```(\w*)\n([\s\S]*?)```/g, function(_, lang, code) {
    codeBlocks.push({ lang: lang, code: escapeHtml(code.replace(/\n$/, '')) });
    return '\x00CODE' + (codeBlocks.length - 1) + '\x00';
  });

  // テーブルを先に処理
  md = md.replace(/^(\|.+\|)\n(\|[\s\-:|]+\|)\n((?:\|.+\|\n?)*)/gm, function(_, header, sep, body) {
    var ths = header.split('|').filter(function(c) { return c.trim() !== ''; });
    var rows = body.trim().split('\n');
    var html = '<table><thead><tr>';
    for (var i = 0; i < ths.length; i++) html += '<th>' + inlineMarkdown(ths[i].trim()) + '</th>';
    html += '</tr></thead><tbody>';
    for (var r = 0; r < rows.length; r++) {
      var tds = rows[r].split('|').filter(function(c) { return c.trim() !== ''; });
      html += '<tr>';
      for (var j = 0; j < tds.length; j++) html += '<td>' + inlineMarkdown(tds[j].trim()) + '</td>';
      html += '</tr>';
    }
    html += '</tbody></table>';
    return html;
  });

  // ブロック要素を処理
  var lines = md.split('\n');
  var html = '';
  var i = 0;
  var inList = false;
  var listType = '';

  while (i < lines.length) {
    var line = lines[i];

    // コードブロックプレースホルダー
    var codeMatch = line.match(/^\x00CODE(\d+)\x00$/);
    if (codeMatch) {
      if (inList) { html += '</' + listType + '>'; inList = false; }
      var cb = codeBlocks[parseInt(codeMatch[1])];
      html += '<pre><code>' + cb.code + '</code></pre>';
      i++; continue;
    }

    // テーブル（すでに置換済み <table>）
    if (line.indexOf('<table>') === 0) {
      if (inList) { html += '</' + listType + '>'; inList = false; }
      html += line;
      i++; continue;
    }

    // 見出し
    var hMatch = line.match(/^(#{1,6})\s+(.+)$/);
    if (hMatch) {
      if (inList) { html += '</' + listType + '>'; inList = false; }
      var level = hMatch[1].length;
      html += '<h' + level + '>' + inlineMarkdown(hMatch[2]) + '</h' + level + '>';
      i++; continue;
    }

    // 水平線
    if (/^(-{3,}|\*{3,}|_{3,})$/.test(line.trim())) {
      if (inList) { html += '</' + listType + '>'; inList = false; }
      html += '<hr>';
      i++; continue;
    }

    // 引用
    if (line.match(/^>\s?/)) {
      if (inList) { html += '</' + listType + '>'; inList = false; }
      var bqLines = [];
      while (i < lines.length && lines[i].match(/^>\s?/)) {
        bqLines.push(lines[i].replace(/^>\s?/, ''));
        i++;
      }
      html += '<blockquote>' + renderMarkdown(bqLines.join('\n')) + '</blockquote>';
      continue;
    }

    // 順序なしリスト
    var ulMatch = line.match(/^(\s*)[*\-+]\s+(.+)$/);
    if (ulMatch) {
      if (!inList || listType !== 'ul') {
        if (inList) html += '</' + listType + '>';
        html += '<ul>'; inList = true; listType = 'ul';
      }
      html += '<li>' + inlineMarkdown(ulMatch[2]) + '</li>';
      i++; continue;
    }

    // 順序付きリスト
    var olMatch = line.match(/^(\s*)\d+\.\s+(.+)$/);
    if (olMatch) {
      if (!inList || listType !== 'ol') {
        if (inList) html += '</' + listType + '>';
        html += '<ol>'; inList = true; listType = 'ol';
      }
      html += '<li>' + inlineMarkdown(olMatch[2]) + '</li>';
      i++; continue;
    }

    // リスト終了
    if (inList && line.trim() === '') {
      html += '</' + listType + '>'; inList = false;
      i++; continue;
    }

    // 空行
    if (line.trim() === '') { i++; continue; }

    // 段落
    if (inList) { html += '</' + listType + '>'; inList = false; }
    var pLines = [line];
    i++;
    while (i < lines.length && lines[i].trim() !== '' && !lines[i].match(/^#{1,6}\s/) && !lines[i].match(/^\x00CODE/) && !lines[i].match(/^[*\-+]\s/) && !lines[i].match(/^\d+\.\s/) && !lines[i].match(/^>\s/) && !lines[i].match(/^(-{3,}|\*{3,}|_{3,})$/) && !lines[i].match(/^\|/)) {
      pLines.push(lines[i]);
      i++;
    }
    html += '<p>' + inlineMarkdown(pLines.join('<br>')) + '</p>';
  }

  if (inList) html += '</' + listType + '>';

  return html;
}

/* --- インラインマークダウン --- */
function inlineMarkdown(text) {
  // 画像
  text = text.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, '<img src="$2" alt="$1">');
  // リンク
  text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
  // 太字+斜体
  text = text.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
  // 太字
  text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  // 斜体
  text = text.replace(/\*(.+?)\*/g, '<em>$1</em>');
  // インラインコード
  text = text.replace(/`([^`]+)`/g, '<code>$1</code>');
  return text;
}

/* --- HTML エスケープ --- */
function escapeHtml(str) {
  return (str == null ? '' : String(str)).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
