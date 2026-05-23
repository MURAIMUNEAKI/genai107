"""
Azure Functions Code Interpreter API (Responses API) 並列テスト（コマンドライン対応版）

使用例（ローカル）:
    python test_api_cli.py --workers 5 --output ./results
    python test_api_cli.py -w 3 -o output

使用例（Azure Functions直接）:
    python test_api_cli.py --api-url https://your-function.azurewebsites.net/api/code-interpreter/responses --api-key YOUR_KEY
    python test_api_cli.py --api-url https://your-function.azurewebsites.net/api/code-interpreter/responses --api-key YOUR_KEY -w 5 -n 10

使用例（API Management経由）:
    python test_api_cli.py --apim --apim-url https://your-apim.azure-api.net --apim-key YOUR_APIM_KEY
    python test_api_cli.py --apim --apim-url https://your-apim.azure-api.net --apim-key YOUR_APIM_KEY -w 5 -n 10
"""
import argparse
import sys
from test_api_parallel import run_parallel_tests


def main():
    parser = argparse.ArgumentParser(
        description="Azure Functions Code Interpreter API (Responses API) 並列テスト"
    )
    
    parser.add_argument(
        "--api-url",
        type=str,
        default="http://localhost:7071/api/code-interpreter/responses",
        help="APIのURL (デフォルト: http://localhost:7071/api/code-interpreter/responses)"
    )
    
    parser.add_argument(
        "--api-key",
        type=str,
        default=None,
        help="Azure Functions用のAPIキー (x-functions-keyヘッダーに設定、ローカルでは不要)"
    )
    
    parser.add_argument(
        "--apim",
        action="store_true",
        help="API Management経由でテストする"
    )
    
    parser.add_argument(
        "--apim-url",
        type=str,
        default=None,
        help="API ManagementのURL (例: https://your-apim.azure-api.net)"
    )
    
    parser.add_argument(
        "--apim-key",
        type=str,
        default=None,
        help="API Managementのサブスクリプションキー (Ocp-Apim-Subscription-Keyヘッダーに設定)"
    )
    
    parser.add_argument(
        "-w", "--workers",
        type=int,
        default=3,
        help="並列度（同時実行数）(デフォルト: 3)"
    )
    
    parser.add_argument(
        "-o", "--output",
        type=str,
        default="output",
        help="出力ディレクトリ (デフォルト: output)"
    )
    
    parser.add_argument(
        "--excel",
        type=str,
        default="sample_data.xlsx",
        help="テスト用Excelファイル (デフォルト: sample_data.xlsx)"
    )
    
    parser.add_argument(
        "-n", "--num-tests",
        type=int,
        default=20,
        help="実行するテストケース数 (デフォルト: 20、最大: 20)"
    )
    
    parser.add_argument(
        "--fix-font",
        action="store_true",
        help="グラフの日本語文字化けを修正する（matplotlib設定を追加）"
    )
    
    args = parser.parse_args()
    
    # テストケース（20個）
    # システムプロンプトはlocal.settings.jsonのSYSTEM_PROMPTで管理
    all_test_cases = [
        {
            "excel_file": args.excel,
            "instruction": "カテゴリ別の売上合計を集計し、棒グラフで可視化してください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "'電子機器'カテゴリの商品を抽出し、日別の売上推移を折れ線グラフで表示してください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "商品別の販売数量を集計し、円グラフで可視化してください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "基本統計情報（平均、中央値、標準偏差）を計算してください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "価格が5000円以上の商品を抽出して、数量との相関を分析してください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "日別の売上推移を時系列グラフで表示してください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "最も売上が高い商品トップ5を抽出してください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "カテゴリごとの平均価格を計算し、横棒グラフで可視化してください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "全体の売上合計を計算してください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "数量と価格の散布図を作成し、相関関係を分析してください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "月別の売上推移を棒グラフで表示してください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "価格帯別（0-5000円、5000-10000円、10000円以上）の商品数を集計してください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "カテゴリ別の商品数を集計し、円グラフで表示してください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "売上が最も低い商品トップ5を抽出してください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "データの欠損値をチェックし、レポートしてください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "価格の分布をヒストグラムで可視化してください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "日別の販売数量合計を折れ線グラフで表示してください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "カテゴリ別の売上シェア（割合）を計算し、表で表示してください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "商品名に'ノート'を含む商品の売上合計を計算してください。"
        },
        {
            "excel_file": args.excel,
            "instruction": "週末（土日）と平日の売上を比較分析してください。"
        }
    ]
    
    # 指定された数だけテストケースを使用
    test_cases = all_test_cases[:min(args.num_tests, len(all_test_cases))]
    
    # API Management使用時の設定
    if args.apim:
        if not args.apim_url:
            print("エラー: --apim-url を指定してください")
            return 1
        if not args.apim_key:
            print("エラー: --apim-key を指定してください")
            return 1
        
        # API ManagementのURL構築（/completionsパスを追加）
        args.api_url = f"{args.apim_url.rstrip('/')}/code-interpreter/responses"
        args.api_key = args.apim_key
        environment = "API Management"
    else:
        # 並列テストを実行
        is_local = "localhost" in args.api_url or "127.0.0.1" in args.api_url
        environment = "ローカル" if is_local else "Azure Functions"
    
    print(f"テスト環境: {environment}")
    print(f"テストケース数: {len(test_cases)}")
    print(f"並列度: {args.workers}")
    print(f"API URL: {args.api_url}")
    print(f"API キー: {'設定あり' if args.api_key else '設定なし'}")
    print(f"Excel ファイル: {args.excel}")
    print(f"出力ディレクトリ: {args.output}")
    print(f"日本語フォント修正: {'有効' if args.fix_font else '無効'}")
    print("-" * 60)
    
    try:
        run_parallel_tests(
            api_url=args.api_url,
            api_key=args.api_key,
            test_cases=test_cases,
            max_workers=args.workers,
            output_dir=args.output
        )
        print("\nすべてのテストが完了しました。")
        return 0
    except KeyboardInterrupt:
        print("\n\nテストが中断されました。")
        return 1
    except Exception as e:
        print(f"\nエラーが発生しました: {e}")
        return 1


if __name__ == "__main__":
    sys.exit(main())
