/**
 * CDKコンテキストから渡される文字列値を適切な型に変換する
 * JSON形式の文字列（[ または { で始まる）のみJSON.parseを試行する
 * パース失敗時はエラーをthrow、それ以外の文字列はそのまま返す
 *
 * @param value - 変換対象の値
 * @returns 変換後の値
 * @throws エラー: JSON形式の文字列が不正な場合
 */
export const parseContextValue = (value: unknown): unknown => {
  // すでに文字列でない場合はそのまま返す（env-parametersから来た値）
  if (typeof value !== 'string') {
    return value;
  }

  // 空文字列はそのまま返す
  if (value === '') {
    return value;
  }

  // JSON形式の文字列（配列またはオブジェクト）かチェック
  const trimmedValue = value.trim();
  if (trimmedValue.startsWith('[') || trimmedValue.startsWith('{')) {
    // JSON parseを試行
    try {
      return JSON.parse(value);
    } catch (error) {
      // parseに失敗した場合はエラーをthrow
      throw new Error(`Failed to parse context value as JSON: ${value}`);
    }
  }

  // JSON形式でない通常の文字列はそのまま返す
  return value;
};

/**
 * オブジェクトの全プロパティに対してparseContextValueを再帰的に適用
 *
 * @param obj - 処理対象のオブジェクト
 * @returns 処理後のオブジェクト
 */
export const preprocessContextValues = (obj: Record<string, unknown>): Record<string, unknown> => {
  const result: Record<string, unknown> = {};

  for (const [key, value] of Object.entries(obj)) {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      // ネストされたオブジェクトの場合は再帰的に処理
      result[key] = preprocessContextValues(value as Record<string, unknown>);
    } else {
      result[key] = parseContextValue(value);
    }
  }

  return result;
};
