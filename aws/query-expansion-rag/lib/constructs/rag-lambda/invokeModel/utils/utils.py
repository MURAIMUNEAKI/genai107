import logging
import re
import sys
import traceback


def getValueForName(inputs: list[dict], target_name: str) -> str | bool:  # noqa: N802
    """
    'name' と 'value' を含む辞書のListから、nameが一致した辞書のvalueを返却する関数

    :param inputs: 入力項目のリスト。各項目は辞書形式で、'name'と'value'キーを持つ。
    :param target_name: 処理対象となる入力項目の名前
    :return: nameが一致した辞書のvalue
    :raises ValueError: 指定された名前の入力が見つからない場合、入力が空の場合、
                        または角括弧で囲まれた項目が見つからない場合
    :raises TypeError: 入力が期待される型でない場合
    :raises Exception: 予期しないエラーが発生した場合
    """
    try:
        if not isinstance(inputs, list):
            raise TypeError("Expected 'inputs' to be a list")

        requested_value = None
        for item in inputs:
            if item.get("name") == target_name:
                requested_value = item.get("value")
                break

        if requested_value is None:
            raise ValueError(f"Input with name '{target_name}' not found")

    except ValueError as ve:
        error_message = f"ValueError in getValueForName: {str(ve)}\n"
        error_message += f"Inputs: {inputs}\n"
        error_message += f"Target name: {target_name}\n"
        error_message += "Stack trace:\n"
        error_message += "".join(traceback.format_tb(sys.exc_info()[2]))
        raise ValueError(error_message) from ve
    except TypeError as te:
        error_message = f"TypeError in getValueForName: {str(te)}\n"
        error_message += f"Inputs: {inputs}\n"
        error_message += f"Target name: {target_name}\n"
        error_message += "Stack trace:\n"
        error_message += "".join(traceback.format_tb(sys.exc_info()[2]))
        raise TypeError(error_message) from te

    return requested_value


def convertToArray(array_string: str) -> list[str]:  # noqa: N802
    """
    文字列の入力から角括弧[]で囲まれた項目をリストに変換する関数。
    ネストされた括弧は考慮されず、最も外側の括弧のみが処理される。
    各項目内の改行で区切られた要素は別々の項目として扱われる。

    :param array_string: リストを表現した文字列
    :raises Exception: 予期しないエラーが発生した場合
    """

    def clean_string(item):
        # 除去したい文字のマッピングを作成
        remove_chars = "\"',"
        trans_table = str.maketrans("", "", remove_chars)
        return item.translate(trans_table).strip()

    try:
        # 正規表現パターン
        pattern = r"\[([^\]]+)\]"

        # 正規表現で一致する部分を抽出
        matches = re.findall(pattern, array_string)
        if not matches:
            raise ValueError("No matches found in the input")

        # 各マッチを改行で分割し、フラットなリストに変換
        return [clean_string(item) for match in matches for item in match.split("\n") if item.strip()]

    except Exception as e:
        error_message = f"An unexpected error occurred in convertToArray: {str(e)}\n"
        error_message += "Stack trace:\n"
        error_message += "".join(traceback.format_tb(sys.exc_info()[2]))
        raise Exception(error_message) from e


def replacePlaceholders(text: str, mapping: dict) -> str:  # noqa: N802
    """
    文字列中の {{key}} プレースホルダを、mapping に含まれる値に置換する関数。

    Args:
        text (str): 元の文字列。例: "Hello, {{ name }}!"
        mapping (dict): プレースホルダのキーと置換する値の辞書。例: {"name": "World"}

    Returns:
        str: プレースホルダが置換された文字列。
    """
    # 正規表現パターン。余分な空白を考慮して \s* を追加。
    pattern = re.compile(r"{{\s*(.*?)\s*}}")

    # 置換時のコールバック関数
    def repl(match: re.Match) -> str:
        key = match.group(1)
        # mappingにキーが存在すればその値で置換、無ければプレースホルダ自体をそのまま返す
        return str(mapping.get(key, match.group(0)))

    return pattern.sub(repl, text)


def handleException(e: Exception, logger: logging.Logger = None) -> None:  # noqa: N802
    """
    例外発生時に詳細な情報を出力するための汎用関数。

    Parameters:
        e (Exception): 発生した例外オブジェクト。
        logger (logging.Logger, optional): 出力先として使用するロギングインスタンス。
            指定しない場合は標準エラー出力(sys.stderr)へ出力します。

    処理内容:
        - sys.exc_info()を用いて現在の例外の型、値、トレースバックを取得
        - traceback.format_exception()を使用して、スタックトレースの情報を文字列に整形
        - 例外の型、例外メッセージ、スタックトレースをひとつのメッセージとして組み立てる。
        - ロガーが渡された場合はlogger.error()で出力し、
          渡されなければ標準エラー出力にprint()で出力する。
    """
    # 現在の例外情報を取得（例外ハンドラ内では sys.exc_info() で正確な情報を得るため）
    exc_type, exc_value, exc_tb = sys.exc_info()

    # スタックトレースを整形する
    stack_trace = "".join(traceback.format_exception(exc_type, exc_value, exc_tb))

    # 詳細なエラーメッセージの作成
    message = (
        f"--- Exception Occurred ---\nType      : {exc_type.__name__}\nMessage   : {str(e)}\nStackTrace:\n{stack_trace}"
    )

    # ログ出力（loggerが指定されていればそのロガーで出力）
    if logger is not None:
        logger.error(message)
    else:
        # 標準エラー出力に出力
        print(message, file=sys.stderr)
