"""
Azure OpenAI Assistantにフォントファイルをアップロードするスクリプト
デプロイ後に自動実行され、ファイルIDを出力します
"""
import os
from openai import AzureOpenAI
from azure.identity import DefaultAzureCredential, get_bearer_token_provider

def main():
    # 環境変数から設定を取得
    endpoint = os.environ.get("AZURE_OPENAI_ENDPOINT")
    deployment = os.environ.get("AZURE_OPENAI_DEPLOYMENT")
    
    if not endpoint:
        raise ValueError("AZURE_OPENAI_ENDPOINT environment variable is required")
    
    print(f"Endpoint: {endpoint}")
    print(f"Deployment: {deployment}")
    
    # Managed IdentityでAzure OpenAIクライアントを作成
    token_provider = get_bearer_token_provider(
        DefaultAzureCredential(),
        "https://cognitiveservices.azure.com/.default"
    )
    
    client = AzureOpenAI(
        azure_endpoint=endpoint,
        azure_ad_token_provider=token_provider,
        api_version="2024-05-01-preview"  # Files APIに必要
    )
    
    # フォントファイルをアップロード
    font_file_path = "font/ipaexg.ttf.zip"
    
    if not os.path.exists(font_file_path):
        raise FileNotFoundError(f"Font file not found: {font_file_path}")
    
    print(f"\nUploading font file: {font_file_path}")
    
    with open(font_file_path, "rb") as f:
        file_response = client.files.create(
            file=f,
            purpose="assistants"
        )
    
    print("[OK] File uploaded successfully!")
    print(f"  File ID: {file_response.id}")
    print(f"  Filename: {file_response.filename}")
    print(f"  Size: {file_response.bytes} bytes")
    print(f"  Created: {file_response.created_at}")
    
    # ファイルIDとその他の環境変数をJSONファイルに出力
    import json
    output_data = {
        "FONT_FILE_ID": file_response.id,
        "AZURE_OPENAI_DEPLOYMENT": os.environ.get("AZURE_OPENAI_DEPLOYMENT"),
        "AZURE_OPENAI_ENDPOINT": os.environ.get("AZURE_OPENAI_ENDPOINT"),
        "OPENAI_MAX_RETRIES": os.environ.get("OPENAI_MAX_RETRIES"),
        "OPENAI_TIMEOUT": os.environ.get("OPENAI_TIMEOUT"),
        "SYSTEM_PROMPT": os.environ.get("SYSTEM_PROMPT")
    }
    
    # None値を除外
    output_data = {k: v for k, v in output_data.items() if v is not None}
    
    output_file = "font_upload_result.json"
    with open(output_file, "w", encoding="utf-8") as f:
        json.dump(output_data, f, indent=2, ensure_ascii=False)
    
    print(f"\n[OK] Settings written to: {output_file}")
    
    return file_response.id

if __name__ == "__main__":
    try:
        file_id = main()
        print(f"\n[OK] Complete! File ID: {file_id}")
    except Exception as e:
        print(f"\n[ERROR] Error: {e}")
        import traceback
        traceback.print_exc()
        exit(1)
