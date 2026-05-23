/* ===== gennai2 API クライアント — SSE/REST 通信ヘルパー ===== */

var ApiClient = (function() {
  var API_BASE = '../api/';

  /**
   * streamChat(opts) — SSE ストリーミングチャット
   * opts: { messages, model, systemPrompt, files, onChunk, onDone, onError }
   */
  function streamChat(opts) {
    var body = {
      messages: opts.messages || [],
      model: opts.model || 'gemini-3.1-flash-lite'
    };
    if (opts.systemPrompt) body.systemPrompt = opts.systemPrompt;
    if (opts.files && opts.files.length) body.files = opts.files;

    var ctrl = new AbortController();
    fetch(API_BASE + 'chat-stream.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      signal: ctrl.signal
    }).then(function(res) {
      if (!res.ok) {
        res.text().then(function(t) {
          if (opts.onError) opts.onError('API Error: ' + res.status + ' ' + t);
        });
        return;
      }
      var reader = res.body.getReader();
      var decoder = new TextDecoder();
      var buf = '';
      var fullText = '';

      function pump() {
        reader.read().then(function(result) {
          if (result.done) {
            // 残りバッファ処理（末尾に改行を付けて最終行も処理させる）
            if (buf.trim()) processLines(buf + '\n');
            if (opts.onDone) opts.onDone(fullText);
            return;
          }
          buf += decoder.decode(result.value, { stream: true });
          processLines(buf);
          buf = buf.substring(buf.lastIndexOf('\n') + 1);
          pump();
        }).catch(function(e) {
          if (e.name !== 'AbortError' && opts.onError) opts.onError(e.message);
        });
      }

      function processLines(text) {
        var lines = text.split('\n');
        // 最後の行は不完全な可能性があるので buf に戻す
        for (var i = 0; i < lines.length - 1; i++) {
          var line = lines[i].trim();
          if (!line) continue;
          try {
            var data = JSON.parse(line);
            if (data.text) {
              fullText += data.text;
              if (opts.onChunk) opts.onChunk(data.text, fullText);
            }
            if (data.error) {
              if (opts.onError) opts.onError(data.error);
            }
          } catch(e) { /* non-JSON line, skip */ }
        }
      }

      pump();
    }).catch(function(e) {
      if (e.name !== 'AbortError' && opts.onError) opts.onError(e.message);
    });

    return { abort: function() { ctrl.abort(); } };
  }

  /**
   * predict(opts) — 非ストリーミング応答
   * opts: { messages, model, systemPrompt }
   * returns Promise<string>
   */
  function predict(opts) {
    return fetch(API_BASE + 'predict.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        messages: opts.messages || [],
        model: opts.model || 'gemini-3.1-flash-lite',
        systemPrompt: opts.systemPrompt || ''
      })
    }).then(function(res) {
      if (!res.ok) throw new Error('API Error: ' + res.status);
      return res.json();
    }).then(function(data) {
      return data.text || data.content || '';
    });
  }

  /**
   * generateImage(opts) — 画像生成
   * opts: { prompt, negativePrompt, aspectRatio }
   * returns Promise<{ image: base64string }>
   */
  function generateImage(opts) {
    return fetch(API_BASE + 'image-generate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        prompt: opts.prompt || '',
        negativePrompt: opts.negativePrompt || '',
        aspectRatio: opts.aspectRatio || '1:1'
      })
    }).then(function(res) {
      return res.text().then(function(text) {
        var data = null;
        try {
          data = text ? JSON.parse(text) : null;
        } catch (e) {}
        if (!res.ok) {
          var detail = (data && data.error) ? data.error : text;
          throw new Error(
            typeof detail === 'string' && detail.length
              ? detail
              : ('画像生成に失敗しました: HTTP ' + res.status)
          );
        }
        return data || {};
      });
    });
  }

  /**
   * uploadFile(file) — ファイルアップロード
   * returns Promise<{ filename, originalName, mimeType, size }>
   */
  function uploadFile(file) {
    var fd = new FormData();
    fd.append('file', file);
    return fetch(API_BASE + 'file-upload.php', {
      method: 'POST',
      body: fd
    }).then(function(res) {
      if (!res.ok) throw new Error('アップロードに失敗しました: ' + res.status);
      return res.json();
    });
  }

  /**
   * deleteFile(filename) — ファイル削除
   */
  function deleteFile(filename) {
    return fetch(API_BASE + 'file-delete.php?file=' + encodeURIComponent(filename), {
      method: 'DELETE'
    }).then(function(res) {
      if (!res.ok) throw new Error('削除に失敗しました');
      return res.json();
    });
  }

  /**
   * transcribe(opts) — 文字起こし
   * opts: { file, language, speakers }
   */
  function transcribe(opts) {
    var fd = new FormData();
    fd.append('file', opts.file);
    if (opts.language) fd.append('language', opts.language);
    if (opts.speakers) fd.append('speakers', opts.speakers);
    return fetch(API_BASE + 'transcribe.php', {
      method: 'POST',
      body: fd
    }).then(function(res) {
      if (!res.ok) {
        return res.json().catch(function() { return {}; }).then(function(data) {
          throw new Error(data.error || '文字起こしに失敗しました: ' + res.status);
        });
      }
      return res.json();
    });
  }

  /**
   * invokeExApp(opts) — ExApp API 呼び出し
   * opts: { appId, payload }
   */
  function invokeExApp(opts) {
    var ctrl = new AbortController();
    var result = fetch(API_BASE + 'exapp-invoke.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        appId: opts.appId,
        payload: opts.payload || {}
      }),
      signal: ctrl.signal
    }).then(function(res) {
      if (!res.ok) throw new Error('ExApp呼び出しに失敗しました: ' + res.status);
      return res.json();
    });
    result.abort = function() { ctrl.abort(); };
    return result;
  }

  /**
   * invokeExAppStream(opts) — ExApp SSE ストリーミング
   * opts: { appId, payload, onChunk, onDone, onError }
   */
  function invokeExAppStream(opts) {
    var ctrl = new AbortController();
    fetch(API_BASE + 'exapp-invoke.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        appId: opts.appId,
        payload: opts.payload || {},
        stream: true
      }),
      signal: ctrl.signal
    }).then(function(res) {
      if (!res.ok) {
        res.text().then(function(t) { if (opts.onError) opts.onError('API Error: ' + res.status); });
        return;
      }
      var reader = res.body.getReader();
      var decoder = new TextDecoder();
      var buf = '';
      var full = '';
      function pump() {
        reader.read().then(function(r) {
          if (r.done) {
            // 残りバッファ処理
            if (buf.trim()) {
              try { var d = JSON.parse(buf.trim()); if (d.text) { full += d.text; } } catch(e) {}
            }
            if (opts.onDone) opts.onDone(full);
            return;
          }
          buf += decoder.decode(r.value, { stream: true });
          var parts = buf.split('\n');
          buf = parts.pop();
          for (var i = 0; i < parts.length; i++) {
            var line = parts[i].trim();
            if (!line) continue;
            try {
              var d = JSON.parse(line);
              if (d.text) { full += d.text; if (opts.onChunk) opts.onChunk(d.text, full); }
            } catch(e) {}
          }
          pump();
        }).catch(function(e) { if (e.name !== 'AbortError' && opts.onError) opts.onError(e.message); });
      }
      pump();
    }).catch(function(e) { if (e.name !== 'AbortError' && opts.onError) opts.onError(e.message); });
    return { abort: function() { ctrl.abort(); } };
  }

  return {
    streamChat: streamChat,
    predict: predict,
    generateImage: generateImage,
    uploadFile: uploadFile,
    deleteFile: deleteFile,
    transcribe: transcribe,
    invokeExApp: invokeExApp,
    invokeExAppStream: invokeExAppStream
  };
})();
