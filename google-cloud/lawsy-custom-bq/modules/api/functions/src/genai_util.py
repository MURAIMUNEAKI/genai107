from flask import jsonify


def create_json_response(data, status_code, headers=None):
    """
    API Gateway V2 に送信するためのレスポンスを生成する
    """
    if headers is None:
        headers = {}

    # デフォルトのCORSヘッダーを設定
    cors_headers = {
        "Access-Control-Allow-Origin": "*",
        "Access-Control-Allow-Headers": "Content-Type,x-api-key",
        "Access-Control-Allow-Methods": "POST,OPTIONS",
    }
    headers.update(cors_headers)

    return jsonify(data), status_code, headers


def response(body, status_code):
    """
    源内Webに送信するためのレスポンスを生成する
    """
    return create_json_response({"outputs": body}, status_code)
