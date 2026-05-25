#!/usr/bin/env python3
# -*- coding: utf-8 -*-
# fix_workflow3.py
# 1) 一発出力アプリ: 承認・まとめ出力を削除（指示をして修正する のみ残す）
# 2) フロー系アプリ: まとめ出力時に承認ボタンを出さない
import os

BASE = os.path.dirname(os.path.abspath(__file__))

# ===== 一発出力アプリから承認・まとめ出力を削除 =====
# fix_workflow.py で追加した CSS
CSS_ADDED = """
.approve-btn{flex:1;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:10px;padding:12px 24px;font-size:0.95rem;font-weight:700;cursor:pointer;transition:all 0.2s;}
.approve-btn:hover{box-shadow:0 4px 16px rgba(16,185,129,0.3);transform:translateY(-1px);}
.summary-btn{background:linear-gradient(135deg,#2563eb,#1d4ed8) !important;}
.summary-btn:hover{box-shadow:0 4px 16px rgba(37,99,235,0.3) !important;}
"""

# fix_workflow.py で追加した HTML
HTML_ADDED = '      <button class="approve-btn" id="approveBtn" onclick="approveAndContinue()" style="display:none">\u627f\u8a8d\u3059\u308b \u2192 \u6b21\u306e\u30b9\u30c6\u30c3\u30d7\u3078</button>\n      <button class="approve-btn summary-btn" id="summaryBtn" onclick="summaryOutput()" style="display:none">\u307e\u3068\u3081\u51fa\u529b</button>\n'

# fix_workflow.py で追加した JS
JS_ADDED = """
var isSummaryMode = false;
function approveAndContinue() {
  if (!currentUid) return;
  doRequest({ uid: currentUid, utterance: '\u627f\u8a8d\u3057\u307e\u3059\u3002\u6b21\u306e\u30b9\u30c6\u30c3\u30d7\u306b\u9032\u3093\u3067\u304f\u3060\u3055\u3044\u3002' });
}
function summaryOutput() {
  if (!currentUid) return;
  isSummaryMode = true;
  outputText = '';
  document.getElementById('outputContent').innerHTML = '';
  doRequest({ uid: currentUid, utterance: '\u3053\u308c\u307e\u3067\u306e\u5168\u30b9\u30c6\u30c3\u30d7\u306e\u5185\u5bb9\u3092\u7d71\u5408\u3057\u3066\u3001\u9014\u4e2d\u306e\u3084\u308a\u53d6\u308a\u3084\u78ba\u8a8d\u3092\u7701\u304d\u3001\u5b8c\u6210\u7248\u306e\u30ec\u30dd\u30fc\u30c8\u3068\u3057\u3066\u5168\u4f53\u3092\u518d\u51fa\u529b\u3057\u3066\u304f\u3060\u3055\u3044\u3002\u898b\u51fa\u3057\u30fb\u7b87\u6761\u66f8\u304d\u30fb\u8868\u3092\u9069\u5207\u306b\u4f7f\u3044\u3001\u305d\u306e\u307e\u307e\u63d0\u51fa\u3067\u304d\u308b\u54c1\u8cea\u306b\u3057\u3066\u304f\u3060\u3055\u3044\u3002' });
}
"""

# 変更された show ロジック（元に戻す）
SHOW_NEW = """if (isSummaryMode) {
        document.getElementById('approveBtn').style.display = 'none';
        document.getElementById('summaryBtn').style.display = 'none';
        isSummaryMode = false;
      } else {
        document.getElementById('approveBtn').style.display = 'block';
        document.getElementById('summaryBtn').style.display = 'block';
      }
      document.getElementById('retryBtn').style.display = 'block';"""
SHOW_ORIG = "document.getElementById('retryBtn').style.display = 'block';"

# 変更された hide ロジック（元に戻す）
HIDE_NEW = """document.getElementById('approveBtn').style.display = 'none';
  document.getElementById('summaryBtn').style.display = 'none';
  document.getElementById('retryBtn').style.display = 'none';"""
HIDE_ORIG = "document.getElementById('retryBtn').style.display = 'none';"

# outputText の累積→上書きに戻す
ACCUMULATE = "outputText += (outputText ? '\\n\\n---\\n\\n' : '') + data.answer"
REPLACE = "outputText = data.answer"

# 一発出力アプリのリスト（fix_workflow.py で変更した14ファイル）
SINGLE_SHOT = [
    'app2.htm', 'app3.htm', 'app4.htm', 'app5.htm', 'app6.htm',
    'app8.htm', 'app10.htm', 'app27.htm', 'app39.htm', 'app43.htm',
    'app58.htm', 'app68.htm', 'app77.htm', 'app120.htm'
]

print('===== 一発出力アプリ: 承認・まとめ出力を削除 =====')
for fname in SINGLE_SHOT:
    filepath = os.path.join(BASE, fname)
    if not os.path.exists(filepath):
        print(f'  SKIP (not found): {fname}')
        continue
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    orig = content

    # 1. CSS 削除
    content = content.replace(CSS_ADDED, '')

    # 2. HTML 削除
    content = content.replace(HTML_ADDED, '')

    # 3. JS 削除
    content = content.replace("var currentUid = '';" + JS_ADDED, "var currentUid = '';")

    # 4. outputText 累積→上書きに戻す
    content = content.replace(ACCUMULATE, REPLACE)

    # 5. show ロジック戻す
    content = content.replace(SHOW_NEW, SHOW_ORIG, 1)

    # 6. hide ロジック戻す
    content = content.replace(HIDE_NEW, HIDE_ORIG, 1)

    if content != orig:
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f'  REVERTED: {fname}')
    else:
        print(f'  NO CHANGE: {fname}')

# ===== フロー系アプリ: まとめ出力時に承認を出さない =====
print()
print('===== フロー系アプリ: まとめ出力時に承認を非表示 =====')

# 現在の else ブロック末尾（承認ボタンの後にまとめ出力も表示している箇所）
ELSE_SHOW_BOTH = """        document.getElementById('approveBtn').onclick = approveAndContinue;
        document.getElementById('summaryBtn').style.display = 'block';"""

# 修正: else ブロックでは承認のみ（まとめ出力は非表示）
ELSE_APPROVE_ONLY = """        document.getElementById('approveBtn').onclick = approveAndContinue;
        document.getElementById('summaryBtn').style.display = 'none';"""

# フロー系アプリ（fix_workflow2.py で変更した9ファイル）
FLOW_APPS = [
    'app54.htm', 'app56.htm', 'app59.htm', 'app60.htm',
    'app75.htm', 'app83.htm', 'app93.htm', 'app99.htm', 'app116.htm'
]

for fname in FLOW_APPS:
    filepath = os.path.join(BASE, fname)
    if not os.path.exists(filepath):
        print(f'  SKIP (not found): {fname}')
        continue
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    orig = content

    content = content.replace(ELSE_SHOW_BOTH, ELSE_APPROVE_ONLY)

    if content != orig:
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f'  FIXED: {fname}')
    else:
        print(f'  NO CHANGE: {fname}')

print()
print('Done.')
