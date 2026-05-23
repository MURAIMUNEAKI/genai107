/* ===== gennai2 共通 履歴ストア (IndexedDB) ===== */

var HistoryStore = (function() {
  var DB_NAME = 'gennai2_history';
  var DB_VERSION = 1;

  function openDB() {
    return new Promise(function(resolve, reject) {
      var req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = function(e) {
        var db = e.target.result;
        if (!db.objectStoreNames.contains('chats')) {
          var store = db.createObjectStore('chats', { keyPath: 'id' });
          store.createIndex('updatedAt', 'updatedAt', { unique: false });
        }
      };
      req.onsuccess = function(e) { resolve(e.target.result); };
      req.onerror = function(e) { reject(e.target.error); };
    });
  }

  /**
   * save(record) — 履歴を保存
   * record: { id, appType, title, data, ... }
   */
  function save(record) {
    record.updatedAt = Date.now();
    openDB().then(function(db) {
      var tx = db.transaction('chats', 'readwrite');
      var req = tx.objectStore('chats').put(record);
      req.onerror = function(e) { console.error('[HistoryStore] put error:', e.target.error); };
      tx.onerror = function(e) { console.error('[HistoryStore] tx error:', e.target.error); };
    }).catch(function(err) {
      console.error('[HistoryStore] openDB error:', err);
    });
  }

  /**
   * load(id) — 履歴を1件取得
   * returns Promise<object|null>
   */
  function load(id) {
    return openDB().then(function(db) {
      return new Promise(function(resolve) {
        var tx = db.transaction('chats', 'readonly');
        var req = tx.objectStore('chats').get(id);
        req.onsuccess = function(e) { resolve(e.target.result || null); };
        req.onerror = function() { resolve(null); };
      });
    });
  }

  /**
   * loadAll() — 全履歴を取得（新しい順）
   * returns Promise<Array>
   */
  function loadAll() {
    return openDB().then(function(db) {
      return new Promise(function(resolve) {
        var tx = db.transaction('chats', 'readonly');
        var req = tx.objectStore('chats').getAll();
        req.onsuccess = function(e) {
          var items = e.target.result || [];
          items.sort(function(a, b) { return (b.updatedAt || 0) - (a.updatedAt || 0); });
          resolve(items);
        };
        req.onerror = function() { resolve([]); };
      });
    }).catch(function() { return []; });
  }

  /**
   * remove(id) — 履歴を1件削除
   * returns Promise
   */
  function remove(id) {
    return openDB().then(function(db) {
      return new Promise(function(resolve) {
        var tx = db.transaction('chats', 'readwrite');
        tx.objectStore('chats').delete(id);
        tx.oncomplete = function() { resolve(); };
        tx.onerror = function() { resolve(); };
      });
    });
  }

  return { openDB: openDB, save: save, load: load, loadAll: loadAll, remove: remove };
})();
