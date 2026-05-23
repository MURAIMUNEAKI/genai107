"""
Azure Functions - Code Interpreter API (Responses API)
CSVファイルをResponses APIのCode Interpreterで分析し、結果を返却するAPI
"""
import azure.functions as func
import logging
import json
import base64
import os
import tempfile
import uuid
from typing import Dict, List, Any, Optional
from openai import OpenAI
from azure.identity import DefaultAzureCredential, get_bearer_token_provider
import httpx

# 定数
DEFAULT_TIMEOUT = 300.0
DEFAULT_MAX_RETRIES = 3
DEFAULT_DOWNLOAD_TIMEOUT = 30.0
COGNITIVE_SERVICES_SCOPE = "https://cognitiveservices.azure.com/.default"

# Azure Functions アプリの初期化
app = func.FunctionApp(http_auth_level=func.AuthLevel.FUNCTION)


def _get_env_variable(key: str, default: Optional[str] = None, required: bool = False) -> Optional[str]:
    """
    環境変数を取得する共通関数
    
    Args:
        key: 環境変数のキー
        default: デフォルト値
        required: 必須フラグ
    
    Returns:
        環境変数の値
        
    Raises:
        ValueError: 必須の環境変数が設定されていない場合
    """
    value = os.environ.get(key, default)
    if required and not value:
        raise ValueError(f"{key} is not set")
    return value


def get_font_file_id() -> Optional[str]:
    """
    事前にアップロード済みのフォントファイルIDを取得
    
    Returns:
        フォントファイルのID、またはNone
    """
    font_file_id = _get_env_variable("FONT_FILE_ID")
    
    if font_file_id:
        logging.debug(f"Using pre-uploaded font file: {font_file_id}")
    else:
        logging.warning("FONT_FILE_ID environment variable is not set")
    
    return font_file_id


def _create_auth_http_client(token_provider: callable) -> httpx.Client:
    """
    認証付きHTTPクライアントを作成
    
    Args:
        token_provider: トークンプロバイダー
        
    Returns:
        認証付きHTTPクライアント
    """
    class AuthHTTPXClient(httpx.Client):
        def __init__(self, token_provider, *args, **kwargs):
            super().__init__(*args, **kwargs)
            self.token_provider = token_provider
        
        def send(self, request, *args, **kwargs):
            request.headers["Authorization"] = f"Bearer {self.token_provider()}"
            return super().send(request, *args, **kwargs)
    
    return AuthHTTPXClient(token_provider=token_provider)


def get_openai_client() -> tuple[OpenAI, callable]:
    """
    OpenAI クライアントとトークンプロバイダーを取得
    
    Returns:
        (client, token_provider) のタプル
    """
    endpoint = _get_env_variable("AZURE_OPENAI_ENDPOINT", required=True)
    base_url = f"{endpoint.rstrip('/')}/openai/v1/"
    
    # トークンプロバイダーの作成
    token_provider = get_bearer_token_provider(
        DefaultAzureCredential(),
        COGNITIVE_SERVICES_SCOPE
    )
    
    # 認証付きHTTPクライアントの作成
    http_client = _create_auth_http_client(token_provider)
    
    # タイムアウトとリトライ回数の取得
    timeout = float(_get_env_variable("OPENAI_TIMEOUT", str(DEFAULT_TIMEOUT)))
    max_retries = int(_get_env_variable("OPENAI_MAX_RETRIES", str(DEFAULT_MAX_RETRIES)))
    
    client = OpenAI(
        base_url=base_url,
        api_key="not-used",
        http_client=http_client,
        timeout=timeout,
        max_retries=max_retries
    )
    
    return client, token_provider


def _create_temp_file(content: bytes, extension: str) -> str:
    """
    一時ファイルを作成
    
    Args:
        content: ファイルの内容
        extension: ファイル拡張子
        
    Returns:
        一時ファイルのパス
    """
    unique_id = str(uuid.uuid4())
    temp_filename = f"{unique_id}{extension}"
    temp_dir = tempfile.gettempdir()
    temp_file_path = os.path.join(temp_dir, temp_filename)
    
    with open(temp_file_path, "wb") as f:
        f.write(content)
    
    return temp_file_path

# ヘルスチェックエンドポイント
@app.route(route="health", methods=["GET"], auth_level=func.AuthLevel.ANONYMOUS)
def health_check(req: func.HttpRequest) -> func.HttpResponse:
    """App Gateway プローブ用のヘルスチェックエンドポイント"""
    client_ip = req.headers.get("X-Forwarded-For") or req.remote_addr
    return func.HttpResponse(
        f"OK\nclient_ip={client_ip}",
        status_code=200
    )

def _cleanup_temp_file(file_path: str) -> None:
    """
    一時ファイルを削除
    
    Args:
        file_path: ファイルパス
    """
    if os.path.exists(file_path):
        try:
            os.remove(file_path)
        except Exception as e:
            logging.warning(f"Failed to delete temp file {file_path}: {e}")


def upload_files_to_assistant(client: OpenAI, files_data: List[Dict[str, str]]) -> tuple[List[str], List[str]]:
    """
    複数のファイルをアシスタントにアップロード
    
    Args:
        client: Azure OpenAI クライアント
        files_data: ファイル情報のリスト [{"filename": "...", "content": "base64..."}]
    
    Returns:
        (file_ids, uploaded_filenames) のタプル
    """
    file_ids = []
    uploaded_filenames = []
    
    for file_info in files_data:
        filename = file_info.get('filename')
        content_base64 = file_info.get('content')
        
        if not filename or not content_base64:
            raise ValueError("各ファイルにはfilenameとcontentが必要です")
        
        file_content = base64.b64decode(content_base64)
        file_extension = os.path.splitext(filename)[1]
        temp_file_path = _create_temp_file(file_content, file_extension)
        
        try:
            with open(temp_file_path, "rb") as f:
                file_obj = client.files.create(file=f, purpose="assistants")
            
            unique_filename = os.path.basename(temp_file_path)
            file_ids.append(file_obj.id)
            uploaded_filenames.append(unique_filename)
            logging.debug(f"File uploaded: {file_obj.id} as {unique_filename} (original: {filename})")
        finally:
            _cleanup_temp_file(temp_file_path)
    
    return file_ids, uploaded_filenames
    



def _download_file_with_httpx(url: str, headers: Dict[str, str], params: Dict[str, str]) -> bytes:
    """
    HTTPXを使用してファイルをダウンロード
    
    Args:
        url: ダウンロードURL
        headers: HTTPヘッダー
        params: URLパラメータ
        
    Returns:
        ファイルの内容
        
    Raises:
        Exception: ダウンロードに失敗した場合
    """
    try:
        with httpx.Client() as http_client:
            response = http_client.get(url, headers=headers, params=params, timeout=DEFAULT_DOWNLOAD_TIMEOUT)
            response.raise_for_status()
            return response.content
    except httpx.RequestError as e:
        logging.error(f"Failed to connect to Azure: {str(e)}")
        raise Exception(f"Failed to connect to Azure: {str(e)}")
    except httpx.HTTPStatusError as e:
        if e.response.status_code == 404:
            logging.error(f"File not found: {url}")
            raise Exception("File not found in container.")
        else:
            logging.error(f"Azure API error: {e.response.status_code}")
            raise Exception(f"Azure API error: {e.response.status_code}")


def download_container_file(client: OpenAI, container_id: str, file_id: str, filename: str, token_provider: callable) -> tuple[str, str]:
    """
    コンテナからファイルをダウンロードしてBase64エンコード
    
    Args:
        client: OpenAI クライアント
        container_id: コンテナID
        file_id: ファイルID
        filename: ファイル名
        token_provider: 認証トークンプロバイダー
    
    Returns:
        (filename, base64_content) のタプル
    """
    endpoint = _get_env_variable("AZURE_OPENAI_ENDPOINT", required=True)
    
    url = f"{endpoint.rstrip('/')}/openai/v1/containers/{container_id}/files/{file_id}/content"
    headers = {
        "Authorization": f"Bearer {token_provider()}",
        "Accept": "*/*"
    }
    
    content = _download_file_with_httpx(url, headers, {})
    content_base64 = base64.b64encode(content).decode('utf-8')
    
    logging.debug(f"Container file downloaded: {container_id}/{file_id} ({filename}), size: {len(content)} bytes")
    return filename, content_base64


def _extract_text_from_message(item: Any, artifacts: List[Dict[str, Any]]) -> str:
    """
    メッセージアイテムからテキストとアーティファクトを抽出
    
    Args:
        item: メッセージアイテム
        artifacts: アーティファクトリスト（出力用）
        
    Returns:
        抽出されたテキスト
    """
    text_content = ""
    
    if hasattr(item, 'content') and item.content:
        for content_item in item.content:
            content_type = getattr(content_item, 'type', None)
            
            if content_type in ('output_text', 'text'):
                text = getattr(content_item, 'text', '')
                if text:
                    text_content += text
                    logging.debug(f"Added text content: {len(text)} chars")
            
            # annotationsから画像ファイルを取得
            if hasattr(content_item, 'annotations') and content_item.annotations:
                for annotation in content_item.annotations:
                    if getattr(annotation, 'type', None) == 'container_file_citation':
                        file_id = getattr(annotation, 'file_id', None)
                        container_id = getattr(annotation, 'container_id', None)
                        filename = getattr(annotation, 'filename', None)
                        
                        if file_id and container_id:
                            logging.debug(f"Found image in annotation: {container_id}/{file_id} ({filename})")
                            artifacts.append({
                                "file_id": file_id,
                                "container_id": container_id,
                                "display_name": filename or f"chart_{len(artifacts)}.png"
                            })
    
    return text_content


def _extract_artifacts_from_code_interpreter(item: Any, artifacts: List[Dict[str, Any]]) -> None:
    """
    コードインタープリターの出力からアーティファクトを抽出
    
    Args:
        item: code_interpreter_callアイテム
        artifacts: アーティファクトリスト（出力用）
    """
    if not hasattr(item, 'outputs'):
        logging.debug("  No 'outputs' attribute found")
        return
    
    logging.debug("  Found 'outputs' attribute")
    outputs = item.outputs
    
    if not outputs:
        logging.debug("  outputs is empty")
        return
    
    logging.debug(f"  outputs count: {len(outputs) if hasattr(outputs, '__len__') else 'N/A'}")
    
    for j, output_item in enumerate(outputs):
        output_type = getattr(output_item, 'type', None)
        logging.debug(f"  outputs[{j}]: type={output_type}")
        
        if output_type == 'image' and hasattr(output_item, 'image'):
            file_id = getattr(output_item.image, 'file_id', None)
            if file_id:
                logging.debug(f"Found image file_id: {file_id}")
                artifacts.append({
                    "file_id": file_id,
                    "display_name": f"chart_{len(artifacts)}.png"
                })


def _parse_response_output(response: Any) -> tuple[str, List[Dict[str, Any]]]:
    """
    Responses APIのレスポンスから出力とアーティファクトを抽出
    
    Args:
        response: Responses APIのレスポンス
        
    Returns:
        (output_text, artifacts) のタプル
    """
    output_text = ""
    artifacts = []
    
    if not hasattr(response, 'output') or not response.output:
        # フォールバック
        if hasattr(response, 'output_text') and response.output_text:
            output_text = response.output_text
            logging.debug(f"Using output_text: {len(output_text)} chars")
        return output_text, artifacts
    
    if not hasattr(response.output, '__iter__') or isinstance(response.output, str):
        output_text = str(response.output)
        logging.debug(f"Using output as string: {len(output_text)} chars")
        return output_text, artifacts
    
    # 配列形式のoutputを処理
    for i, item in enumerate(response.output):
        item_type = getattr(item, 'type', None)
        logging.debug(f"Processing output[{i}]: type={item_type}")
        
        if item_type == 'message':
            output_text += _extract_text_from_message(item, artifacts)
        elif item_type == 'code_interpreter_call':
            _extract_artifacts_from_code_interpreter(item, artifacts)
    
    return output_text, artifacts


def run_code_interpreter_with_responses_api(
    client: OpenAI,
    input_text: str,
    file_ids: List[str],
    uploaded_filenames: List[str] = None
) -> Dict[str, Any]:
    """
    Responses APIを使用してCode Interpreterで分析
    
    Args:
        client: Azure OpenAI クライアント
        input_text: ユーザーからの指示テキスト
        file_ids: アップロード済みファイルIDのリスト
        uploaded_filenames: アップロードしたファイルの名前リスト(UUID付き)
    
    Returns:
        分析結果を含む辞書 {output_text, artifacts}
    """
    deployment = _get_env_variable("AZURE_OPENAI_DEPLOYMENT_NAME", required=True)
    instructions = _get_env_variable("SYSTEM_PROMPT")
    
    # ファイル名情報を追加
    if uploaded_filenames:
        file_info = f"\n\nアップロードされた分析対象ファイル: {', '.join(uploaded_filenames)}"
        input_text += file_info
    
    # デバッグログ
    logging.debug("=" * 60)
    logging.debug(f"Model: {deployment}, File IDs: {file_ids}")
    logging.debug(f"Instructions: {instructions[:100]}...")
    logging.debug(f"Input text: {input_text[:100]}...")
    logging.debug("=" * 60)
    
    try:
        response = client.responses.create(
            model=deployment,
            tools=[{
                "type": "code_interpreter",
                "container": {"type": "auto", "file_ids": file_ids}
            }],
            instructions=instructions,
            input=input_text
        )
        
        logging.debug(f"Response received: {response.id}")
        output_text, artifacts = _parse_response_output(response)
        logging.debug(f"Final: output_text={len(output_text)} chars, artifacts={len(artifacts)}")
        
        return {"output_text": output_text, "artifacts": artifacts}
    
    except Exception as e:
        logging.error(f"Responses API error: {str(e)}", exc_info=True)
        raise


def _create_error_response(message: str, status_code: int = 400) -> func.HttpResponse:
    """
    エラーレスポンスを作成
    
    Args:
        message: エラーメッセージ
        status_code: HTTPステータスコード
        
    Returns:
        HTTPレスポンス
    """
    return func.HttpResponse(
        json.dumps({"error": message}, ensure_ascii=False),
        status_code=status_code,
        mimetype="application/json"
    )


def _create_success_response(data: Dict[str, Any]) -> func.HttpResponse:
    """
    成功レスポンスを作成
    
    Args:
        data: レスポンスデータ
        
    Returns:
        HTTPレスポンス
    """
    return func.HttpResponse(
        json.dumps(data, ensure_ascii=False),
        status_code=200,
        mimetype="application/json"
    )


def _validate_request_body(req_body: Dict[str, Any]) -> tuple[str, List[Dict[str, Any]], Optional[str]]:
    """
    リクエストボディを検証
    
    Args:
        req_body: リクエストボディ
        
    Returns:
        (input_text, all_files, error_message) のタプル
    """
    inputs = req_body.get('inputs', {})
    input_text = inputs.get('input_text')
    files_groups = inputs.get('files', [])
    
    if not input_text:
        return None, None, "input_textは必須です。"
    
    # filesは任意パラメータ
    all_files = []
    if files_groups:
        if not isinstance(files_groups, list):
            return None, None, "filesは配列形式である必要があります。"
        
        # すべてのファイルグループからファイルを収集
        for file_group in files_groups:
            files_in_group = file_group.get('files', [])
            if isinstance(files_in_group, list):
                all_files.extend(files_in_group)
    
    return input_text, all_files, None


def _process_artifacts(artifacts: List[Dict[str, Any]], client: OpenAI, token_provider: callable) -> List[Dict[str, Any]]:
    """
    アーティファクトを処理してダウンロード
    
    Args:
        artifacts: アーティファクトリスト
        client: OpenAI クライアント
        token_provider: トークンプロバイダー
        
    Returns:
        処理済みアーティファクトリスト
    """
    processed_artifacts = []
    
    for artifact in artifacts:
        if 'file_id' in artifact:
            file_id = artifact['file_id']
            container_id = artifact.get('container_id')
            filename = artifact.get('display_name', 'output.png')
            
            try:
                filename, content_base64 = download_container_file(
                    client, container_id, file_id, filename, token_provider
                )
                
                processed_artifacts.append({
                    "display_name": artifact.get('display_name', filename),
                    "content": content_base64
                })
                logging.debug(f"Downloaded file: {file_id} -> {filename}")
            except Exception as e:
                logging.error(f"Failed to download file {file_id}: {e}")
        elif 'content' in artifact:
            processed_artifacts.append(artifact)
    
    return processed_artifacts


def _cleanup_uploaded_files(client: OpenAI, file_ids: List[str], font_file_id: Optional[str]) -> None:
    """
    アップロードしたファイルを削除
    
    Args:
        client: OpenAI クライアント
        file_ids: ファイルIDリスト
        font_file_id: フォントファイルID（削除しない）
    """
    for file_id in file_ids:
        if file_id == font_file_id:
            logging.debug(f"Skipped font file deletion: {file_id}")
            continue
        
        try:
            client.files.delete(file_id)
            logging.debug(f"Deleted uploaded file: {file_id}")
        except Exception as e:
            logging.warning(f"Failed to delete file {file_id}: {e}")


#@app.route(route="code-interpreter/responses", methods=["POST"])
@app.route(route="responses", methods=["POST"])
def code_interpreter_responses_endpoint(req: func.HttpRequest) -> func.HttpResponse:
    """Code Interpreter API エンドポイント"""
    logging.debug('Processing code-interpreter/responses request')
    
    try:
        # リクエストボディの解析
        try:
            req_body = req.get_json()
        except ValueError:
            return _create_error_response("リクエストボディが不正です。JSON形式で送信してください。")
        
        # リクエストの検証
        input_text, all_files, error = _validate_request_body(req_body)
        if error:
            return _create_error_response(error)
        
        # クライアントの取得
        client, token_provider = get_openai_client()
        
        # ファイルのアップロード（ファイルがある場合のみ）
        file_ids = []
        uploaded_filenames = []
        if all_files:
            file_ids, uploaded_filenames = upload_files_to_assistant(client, all_files)
            logging.debug(f"Uploaded {len(file_ids)} file(s)")
        else:
            logging.debug("No files to upload")
        
        # フォントファイルの追加
        font_file_id = get_font_file_id()
        if font_file_id:
            file_ids.append(font_file_id)
            logging.debug(f"Using font file: {font_file_id}")
        
        # Code Interpreterの実行
        result = run_code_interpreter_with_responses_api(
            client, input_text, file_ids, uploaded_filenames
        )
        
        # アーティファクトの処理
        processed_artifacts = _process_artifacts(
            result.get('artifacts', []), client, token_provider
        )
        
        # レスポンスの作成
        response_data = {
            "outputs": result.get('output_text', ''),
            "artifacts": processed_artifacts
        }
        
        # クリーンアップ
        _cleanup_uploaded_files(client, file_ids, font_file_id)
        
        return _create_success_response(response_data)
    
    except Exception as e:
        logging.error(f"Error processing request: {str(e)}", exc_info=True)
        return _create_error_response(
            f"リクエストの処理中にエラーが発生しました: {str(e)}",
            status_code=500
        )
