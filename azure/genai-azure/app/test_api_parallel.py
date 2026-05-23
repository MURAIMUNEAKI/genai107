"""
Azure Functions Code Interpreter API の並列テストスクリプト
"""
import requests
import base64
import json
import os
import time
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime
from typing import Dict, Any, List
from urllib.parse import urlparse


def encode_file_to_base64(file_path: str) -> str:
    """ファイルをBase64エンコード"""
    with open(file_path, 'rb') as f:
        return base64.b64encode(f.read()).decode('utf-8')


def call_api(
    api_url: str,
    api_key: str,
    excel_file_path: str,
    instruction: str,
    test_id: int,
    output_dir: str = "output",
    timestamp: str = None
) -> Dict[str, Any]:
    """
    APIを呼び出す
    
    Args:
        api_url: APIのURL
        api_key: x-api-keyヘッダーに設定する値
        excel_file_path: テスト用Excelファイルのパス
        instruction: Code Interpreterへの指示文
        test_id: テストID
        output_dir: 出力ディレクトリ
        timestamp: タイムスタンプ文字列（yyyymmddhhmmss形式）
    
    Returns:
        テスト結果の辞書
    """
    start_time = time.time()
    result = {
        "test_id": test_id,
        "status": "failed",
        "start_time": datetime.now().isoformat(),
        "error": None,
        "response_time": 0,
        "output_files": []
    }
    
    try:
        print(f"[テスト {test_id}] 🚀 開始: {instruction[:50]}...")
        
        # ファイルをBase64エンコード
        base64_content = encode_file_to_base64(excel_file_path)
        
        # リクエストボディを作成（Responses API形式）
        request_body = {
            "inputs": {
                "input_text": instruction,
                "files": [
                    {
                        "key": "excel_file",
                        "files": [
                            {
                                "filename": Path(excel_file_path).name,
                                "content": base64_content
                            }
                        ]
                    }
                ]
            }
        }
        
        # ヘッダーの設定
        headers = {
            "Content-Type": "application/json"
        }
        
        # 認証ヘッダーの設定
        if api_key:
            hostname = (urlparse(api_url).hostname or "").lower()
            if hostname.endswith(".azure-api.net") or hostname == "azure-api.net" or "apim" in hostname:
                # API Management: サブスクリプションキーを2つの方法で送信
                headers["x-api-key"] = api_key
                #headers["subscription-key"] = api_key  # 代替ヘッダー
            elif hostname.endswith(".azurewebsites.net") or hostname == "azurewebsites.net":
                # Azure Functions: x-functions-keyヘッダー
                headers["x-functions-key"] = api_key
            # ローカルの場合は認証ヘッダーなし
        # APIリクエスト
        response = requests.post(
            api_url,
            headers=headers,
            json=request_body,
            timeout=300
        )
        
        elapsed_time = time.time() - start_time
        result["response_time"] = elapsed_time
        result["status_code"] = response.status_code
        
        # レスポンスを解析
        try:
            response_data = response.json()
        except json.JSONDecodeError as e:
            result["status"] = "failed"
            result["error"] = f"JSON decode error: {str(e)}"
            result["response_text"] = response.text[:500]  # 最初の500文字を記録
            print(f"❌ エラー: {result['error']}")
            print(f"ステータスコード: {response.status_code}")
            print(f"レスポンス: {response.text[:500]}")
            return result
        
        if response.status_code == 200:
            result["status"] = "success"
            # Responses API形式のレスポンスを処理
            output_text = response_data.get("outputs", "")
            artifacts = response_data.get("artifacts", [])
            
            # 出力ディレクトリを作成（タイムスタンプ付き）
            if timestamp:
                test_output_dir = os.path.join(output_dir, f"test_{test_id}", timestamp)
            else:
                test_output_dir = os.path.join(output_dir, f"test_{test_id}")
            os.makedirs(test_output_dir, exist_ok=True)
            
            # テキスト出力を保存
            text_file_path = os.path.join(test_output_dir, "output.txt")
            with open(text_file_path, 'w', encoding='utf-8') as f:
                f.write(f"テストID: {test_id}\n")
                f.write(f"指示: {instruction}\n")
                f.write(f"レスポンス時間: {elapsed_time:.2f}秒\n")
                f.write(f"\n{'='*60}\n")
                f.write(f"出力テキスト:\n{output_text}\n")
            
            result["output_text_file"] = text_file_path
            
            # artifactsを保存
            if artifacts:
                for i, artifact in enumerate(artifacts):
                    display_name = artifact.get("display_name", f"output_{i}.png")
                    content = artifact.get("content")
                    
                    if content:
                        file_data = base64.b64decode(content)
                        output_file_path = os.path.join(test_output_dir, display_name)
                        
                        with open(output_file_path, 'wb') as f:
                            f.write(file_data)
                        
                        result["output_files"].append(output_file_path)
            
            print(f"[テスト {test_id}] ✅ 成功 ({elapsed_time:.2f}秒)")
        else:
            result["status"] = "failed"
            error_detail = response_data.get("error", "Unknown error")
            result["error"] = error_detail
            result["response_body"] = response_data  # 完全なレスポンスを記録
            print(f"[テスト {test_id}] ❌ 失敗 (ステータス: {response.status_code})")
            print(f"  エラー詳細: {error_detail}")
            # エラーが辞書型の場合は完全なレスポンスを表示
            if isinstance(error_detail, dict) or "error" in response_data:
                print(f"  レスポンス全体: {json.dumps(response_data, ensure_ascii=False, indent=2)}")
    
    except requests.exceptions.Timeout:
        result["error"] = "リクエストがタイムアウトしました"
        print(f"[テスト {test_id}] ⏱️ タイムアウト")
    except Exception as e:
        result["error"] = str(e)
        print(f"[テスト {test_id}] ❌ エラー: {e}")
    
    result["end_time"] = datetime.now().isoformat()
    return result


def run_parallel_tests(
    api_url: str,
    api_key: str,
    test_cases: List[Dict[str, str]],
    max_workers: int = 3,
    output_dir: str = "output"
) -> List[Dict[str, Any]]:
    """
    並列でAPIテストを実行
    
    Args:
        api_url: APIのURL
        api_key: APIキー
        test_cases: テストケースのリスト [{"excel_file": "", "instruction": ""}, ...]
        max_workers: 並列度（同時実行数）
        output_dir: 出力ディレクトリ
    
    Returns:
        テスト結果のリスト
    """
    # タイムスタンプを生成（yyyymmddhhmmss形式）
    timestamp = datetime.now().strftime("%Y%m%d%H%M%S")
    
    # 出力ディレクトリを作成
    os.makedirs(output_dir, exist_ok=True)
    
    print("=" * 70)
    print(f"Azure Functions Code Interpreter API 並列テスト")
    print(f"実行時刻: {timestamp}")
    print(f"並列度: {max_workers}")
    print(f"テストケース数: {len(test_cases)}")
    print(f"出力先: {output_dir}")
    print("=" * 70)
    print()
    
    results = []
    start_time = time.time()
    
    # ThreadPoolExecutorで並列実行
    with ThreadPoolExecutor(max_workers=max_workers) as executor:
        # タスクを投入
        future_to_test = {
            executor.submit(
                call_api,
                api_url,
                api_key,
                test_case["excel_file"],
                test_case["instruction"],
                i + 1,
                output_dir,
                timestamp
            ): i + 1
            for i, test_case in enumerate(test_cases)
        }
        
        # 完了したタスクから結果を取得
        for future in as_completed(future_to_test):
            test_id = future_to_test[future]
            try:
                result = future.result()
                results.append(result)
            except Exception as e:
                print(f"[テスト {test_id}] ❌ 予期しないエラー: {e}")
                results.append({
                    "test_id": test_id,
                    "status": "failed",
                    "error": str(e)
                })
    
    total_time = time.time() - start_time
    
    # 結果をサマリー
    print()
    print("=" * 70)
    print("テスト結果サマリー")
    print("=" * 70)
    
    success_count = sum(1 for r in results if r["status"] == "success")
    failed_count = len(results) - success_count
    avg_response_time = sum(r.get("response_time", 0) for r in results) / len(results) if results else 0
    
    print(f"総テスト数: {len(results)}")
    print(f"成功: {success_count}")
    print(f"失敗: {failed_count}")
    print(f"平均レスポンス時間: {avg_response_time:.2f}秒")
    print(f"総実行時間: {total_time:.2f}秒")
    print()
    
    # 詳細結果を表示
    for result in sorted(results, key=lambda x: x["test_id"]):
        status_icon = "✅" if result["status"] == "success" else "❌"
        print(f"{status_icon} テスト {result['test_id']}: {result['status']}")
        if result["status"] == "success":
            print(f"   レスポンス時間: {result['response_time']:.2f}秒")
            if result.get("output_files"):
                print(f"   出力ファイル数: {len(result['output_files'])}")
        else:
            print(f"   エラー: {result.get('error', 'Unknown')}")
        print()
    
    # サマリーをJSONで保存（タイムスタンプ付き）
    summary_file = os.path.join(output_dir, f"test_summary_{timestamp}.json")
    with open(summary_file, 'w', encoding='utf-8') as f:
        json.dump({
            "timestamp": timestamp,
            "total_tests": len(results),
            "success_count": success_count,
            "failed_count": failed_count,
            "avg_response_time": avg_response_time,
            "total_execution_time": total_time,
            "max_workers": max_workers,
            "results": results
        }, f, indent=2, ensure_ascii=False)
    
    print(f"📄 サマリーファイル: {summary_file}")
    print("=" * 70)
    
    return results


def main():
    """メイン関数（直接実行用、コマンドライン引数で上書き可能）"""
    import sys
    
    # デフォルト設定（ローカル環境）
    API_URL = "http://localhost:7071/api/code-interpreter/responses"
    API_KEY = None  # ローカルでは認証なし
    MAX_WORKERS = 3  # 並列度（同時実行数）
    OUTPUT_DIR = "output"  # 出力ディレクトリ
    
    # コマンドライン引数で上書き（簡易版）
    if len(sys.argv) > 1:
        print("使用方法: python test_api_parallel.py")
        print("または: python test_api_cli.py --api-url <URL> --api-key <KEY> ...")
        return
    
    # テストケース（20個）
    test_cases = [
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルのカテゴリ別の売上合計を集計し、棒グラフで可視化してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルから'電子機器'カテゴリの商品を抽出し、日別の売上推移を折れ線グラフで表示してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルの商品別の販売数量を集計し、円グラフで可視化してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルの基本統計情報（平均、中央値、標準偏差）を計算してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルから価格が5000円以上の商品を抽出して、数量との相関を分析してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルの日別の売上推移を時系列グラフで表示してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルから最も売上が高い商品トップ5を抽出してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルのカテゴリ別の平均価格を計算して、比較グラフを作成してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルの数量と価格の散布図を作成してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルから'家具'カテゴリの商品の売上合計を計算してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルの週別の売上傾向を分析してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルから数量が10個以上の商品を抽出してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルのカテゴリ別の商品数を集計してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルの価格帯別（0-10000円、10001-50000円、50001円以上）の売上を分析してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルから最も販売数量が多い商品を特定してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルの日付ごとの取引件数を棒グラフで表示してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルの売上と数量の相関係数を計算してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルからノートPCの売上推移を分析してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルの全体的な売上トレンドを要約してください。"
        },
        {
            "excel_file": "sample_data.xlsx",
            "instruction": "このExcelファイルのカテゴリ別の売上構成比を円グラフで表示してください。"
        }
    ]
    
    # 並列テスト実行
    results = run_parallel_tests(
        api_url=API_URL,
        api_key=API_KEY,
        test_cases=test_cases,
        max_workers=MAX_WORKERS,
        output_dir=OUTPUT_DIR
    )
    
    return results


if __name__ == "__main__":
    main()
