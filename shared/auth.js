/* ===== gennai2 認証ガード ===== */

/**
 * checkAuth() — sessionStorage 確認、未認証なら index.htm にリダイレクト
 */
function checkAuth() {
  if (sessionStorage.getItem('gennai_auth') !== 'true') {
    window.location.href = '../index.htm';
    return false;
  }
  return true;
}

/**
 * doSignout() — セッション破棄 + リダイレクト
 */
function doSignout() {
  sessionStorage.removeItem('gennai_auth');
  window.location.href = '../index.htm';
}
