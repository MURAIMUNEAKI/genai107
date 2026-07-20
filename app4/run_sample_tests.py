# -*- coding: utf-8 -*-
# app4 サンプル書類 × 2回ずつ採点テスト（OpenAI gpt-5.4-nano 安定性実験）
# 使い方: PHP_CLI_SERVER_WORKERS=10 php -S 127.0.0.1:8098 をリポジトリルートで起動してから実行
import io, json, os, re, sys, urllib.request
from concurrent.futures import ThreadPoolExecutor
from pypdf import PdfReader

BASE = 'http://127.0.0.1:8098/app4/'
DIR = 'samples/'
RUNS = int(os.environ.get('RUNS', '2'))

# (app, file, doc_type or None or 'auto', 想定ラベル)
SAMPLES = [
    ('app13', 'care_plan_1_tanaka.pdf',            'kyotaku', '優良(A想定)'),
    ('app13', 'care_plan_2_suzuki.pdf',            'kyotaku', '要改善'),
    ('app13', 'care_plan_3_takahashi.pdf',         'kyotaku', '軽微'),
    ('app13', 'care_plan_4_sasaki.pdf',            'kyotaku', '要改善'),
    ('app13', 'care_plan_5_nakamura.pdf',          'kyotaku', '重大'),
    ('app14', 'individual_support_plan_1.pdf',     'auto14', '優良(A想定)'),
    ('app14', 'individual_support_plan_2.pdf',     'auto14', '重大'),
    ('app14', 'individual_support_plan_3.pdf',     'auto14', '要改善'),
    ('app14', 'individual_support_plan_4.pdf',     'auto14', '要改善'),
    ('app14', 'individual_support_plan_5.pdf',     'auto14', '重大'),
    ('app15', 'rules_facility_1.pdf',              'auto15', '優良(A想定)'),
    ('app15', 'rules_facility_2.pdf',              'auto15', '要改善'),
    ('app15', 'rules_facility_3.pdf',              'auto15', '優良'),
    ('app15', 'rules_facility_4.pdf',              'auto15', '重大'),
    ('app15', 'rules_facility_5.pdf',              'auto15', '要改善'),
    ('app16', 'genmen_1_shitsugyo.pdf',            None,     '適正(A想定)'),
    ('app16', 'genmen_2_shotokugekigen.pdf',       None,     '要改善'),
    ('app16', 'genmen_3_taisyogai.pdf',            None,     '重大'),
    ('app16', 'genmen_4_saigai.pdf',               None,     '適正'),
    ('app16', 'genmen_5_shibou.pdf',               None,     '要改善'),
    ('app17', 'seiho_soudan_1_kourei.pdf',         None,     '適正(A想定)'),
    ('app17', 'seiho_soudan_2_shitsugyo.pdf',      None,     '要改善'),
    ('app17', 'seiho_soudan_3_boshi.pdf',          None,     '要改善'),
    ('app17', 'seiho_soudan_4_shoubyou.pdf',       None,     '重大'),
    ('app18', 'seiho_shinsei_1_tekisei.pdf',       None,     '適正(A想定)'),
    ('app18', 'seiho_shinsei_2_yokin.pdf',         None,     '要改善'),
    ('app18', 'seiho_shinsei_3_shisan.pdf',        None,     '重大'),
    ('app18', 'seiho_shinsei_4_tahou.pdf',         None,     '要改善'),
    ('app18', 'seiho_shinsei_5_keishiki.pdf',      None,     '要改善'),
    ('app19', 'seiho_kaishigo_1_houshin_ok.pdf',   'houshin',   '適正(A想定)'),
    ('app19', 'seiho_kaishigo_2_houshin_ng.pdf',   'houshin',   '要改善'),
    ('app19', 'seiho_kaishigo_3_63jou.pdf',        'kiroku',    '63条検出'),
    ('app19', 'seiho_kaishigo_4_78jou.pdf',        'kiroku',    '78条検出'),
    ('app19', 'seiho_kaishigo_5_teihaishi.pdf',    'teihaishi', '重大'),
    ('app20', 'keiji_1_genmen_ok.pdf',             'genmen',  '適正(A想定)'),
    ('app20', 'keiji_2_genmen_ng.pdf',             'genmen',  '要改善'),
    ('app20', 'keiji_3_meigi.pdf',                 'meigi',   '要改善'),
    ('app20', 'keiji_4_haisha.pdf',                'haisha',  '重大'),
    ('app20', 'keiji_5_taxdome.pdf',               'taxdome', '適正(A想定)'),
]


def detect14(text):
    if '児童発達' in text or '放課後等デイ' in text: return 'jido'
    if '共同生活援助' in text or 'グループホーム' in text: return 'gh'
    if '生活介護' in text: return 'seikatsu'
    return 'shuro'


def detect15(text):
    if '保育' in text or 'こども園' in text: return 'hoiku'
    if '障害' in text and ('生活介護' in text or '就労' in text): return 'shogai'
    return 'kaigo'


def extract(path):
    r = PdfReader(path)
    return '\n'.join((p.extract_text() or '') for p in r.pages)


def call(app, payload):
    req = urllib.request.Request(BASE + app + '.php',
                                 data=json.dumps(payload, ensure_ascii=False).encode('utf-8'),
                                 headers={'Content-Type': 'application/json'})
    out, err, verdict = '', '', None
    with urllib.request.urlopen(req, timeout=300) as res:
        for line in res.read().decode('utf-8', 'replace').split('\n'):
            if not line.startswith('data: '): continue
            s = line[6:].strip()
            if s == '[DONE]' or s == '': continue
            try: j = json.loads(s)
            except Exception: continue
            if 'text' in j: out += j['text']
            if 'error' in j: err = j['error']
            if 'verdict' in j: verdict = j['verdict']
    return out, err, verdict


def parse_verdict(v):
    # フロントの parseVerdict と同じ: 1から始まる連番1周分のみ
    out = []
    for m in re.finditer(r'(\d+)\s*[:：]\s*([○◯△×])', v or ''):
        idx = int(m.group(1))
        if idx == 1 and out: break
        if idx != len(out) + 1: continue
        out.append(m.group(2).replace('◯', '○'))
    return out if len(out) >= 4 else None


ROW = re.compile(r'\|\s*(\d+)\s*\|[^|]+\|\s*(\d+)\s*点?\s*\|\s*([○△×◯])[^|]*\|\s*(\d+)\s*点?\s*\|')
TOTAL = re.compile(r'合計点[^0-9]*(\d+)\s*/\s*100')
RANK = re.compile(r'評価ランク[：:]\s*[〔\[]?\s*([A-D])')


def rank_of(total):
    return 'A' if total >= 90 else 'B' if total >= 75 else 'C' if total >= 60 else 'D'


def fixed_score(mark, hai):
    if mark == '○': return hai
    if mark == '△': return 8 if hai >= 15 else (5 if hai >= 10 else 3)
    return 0


def parse(out, marks=None):
    # フロントの reconcileScore と同じ計算：重複行スキップ・確定判定で上書き・判定×配点で得点決定
    seen, judged_list, scores = set(), [], []
    for no_s, hai_s, mark, _pts in ROW.findall(out):
        no, hai = int(no_s), int(hai_s)
        if no in seen: continue
        if marks and no > len(marks): continue
        seen.add(no)
        m = marks[no - 1] if (marks and no <= len(marks)) else mark.replace('◯', '○')
        judged_list.append(m)
        scores.append(fixed_score(m, hai))
    judged = ''.join(judged_list)
    total = sum(scores)
    m = TOTAL.search(out)
    reported = int(m.group(1)) if m else None
    mr = RANK.search(out)
    jou = ''
    m63 = re.search(r'該当条文の見立て[：:]\s*(.{0,30})', out)
    if m63: jou = m63.group(1).strip()
    return {'judged': judged, 'scores': scores, 'total': total,
            'rank': rank_of(total) if scores else '?',
            'reported_total': reported, 'reported_rank': mr.group(1) if mr else None,
            'jou': jou}


def run_one(item):
    app, fname, dt, expected = item
    text = extract(DIR + fname) if fname.endswith('.pdf') else io.open(DIR + fname, encoding='utf-8').read()
    if dt == 'auto14': dt = detect14(text)
    elif dt == 'auto15': dt = detect15(text)
    payload = {'text': text}
    if dt: payload['doc_type'] = dt
    runs = []
    for i in range(RUNS):
        out, err, verdict = call(app, payload)
        p = parse(out, parse_verdict(verdict))
        p['verdict'] = verdict
        p['error'] = err
        p['len'] = len(out)
        runs.append(p)
        io.open('testout_%s_%s_run%d.md' % (app, fname.replace('.pdf', ''), i + 1), 'w', encoding='utf-8').write(out)
    stable = all(r['judged'] == runs[0]['judged'] and r['scores'] == runs[0]['scores'] for r in runs)
    return {'app': app, 'file': fname, 'doc_type': dt, 'expected': expected,
            'runs': runs, 'stable': stable}


def main():
    only = sys.argv[1:] if len(sys.argv) > 1 else None
    items = [s for s in SAMPLES if only is None or s[0] in only or s[1] in only]
    with ThreadPoolExecutor(max_workers=6) as ex:
        results = list(ex.map(run_one, items))
    io.open('test_results.json', 'w', encoding='utf-8').write(json.dumps(results, ensure_ascii=False, indent=1))
    print('%-6s %-32s %-9s %-12s %-14s %-14s %s' % ('app', 'file', 'doctype', 'expected', 'run1', 'run2', 'stable'))
    for r in results:
        fs = ' '.join('%s(%s)%s' % (x['total'], x['rank'], 'ERR' if x['error'] else '') for x in r['runs'])
        print('%-6s %-32s %-9s %-12s %s %s' % (r['app'], r['file'], r['doc_type'] or '-', r['expected'], fs, 'OK' if r['stable'] else 'UNSTABLE'))
        print('       判定: %s%s' % (' / '.join(x['judged'] for x in r['runs']), ('  条文:' + r['runs'][0]['jou']) if r['runs'][0]['jou'] else ''))
        for x in r['runs']:
            if x['error']:
                print('       ERROR:', x['error'])
                break


if __name__ == '__main__':
    main()
