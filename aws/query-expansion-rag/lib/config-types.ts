// 設定の型定義
export interface ModelParams {
  maxTokens: number;
  temperature?: number;
  topP?: number;
  topK?: number;
  stopSequences?: string[];
}

// 処理タイプ別設定ファイルの構造
export interface TypeConfig {
  modelId: string;
  maxTokens: number;
  temperature: number;
  topP: number;
  topK?: number;
  systemPrompt?: string;
  stopSequences?: string[];
}

// アプリ個別の設定ファイル フォーマット定義
export interface AppConfig {
  name: string;
  description?: string;
  idcUserNames: string[];
  responseFooter?: string;
  logLevel?: 'DEBUG' | 'INFO' | 'WARN' | 'ERROR';
  answer_generation?: Record<string, any>;
  answer_generation_detail?: Record<string, any>;
  relevance_rating?: Record<string, any>;
  retrieve_and_generate?: Record<string, any>;
}