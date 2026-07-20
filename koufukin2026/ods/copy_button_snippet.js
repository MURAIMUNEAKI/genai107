// このコードを shinsei1.htm の 608行目の後に追加してください

// 6. Add Copy Button
const copyBtn = document.createElement('button');
copyBtn.innerText = "提案書案をコピー";
copyBtn.style.marginTop = '2rem';
copyBtn.style.width = '100%';
copyBtn.style.background = '#0ea5e9'; // Sky blue
copyBtn.style.color = 'white';
copyBtn.style.border = 'none';
copyBtn.style.padding = '1rem 2rem';
copyBtn.style.borderRadius = '0.5rem'; // Rounded corners
copyBtn.style.fontWeight = 'bold';
copyBtn.style.fontSize = '1.1rem';
copyBtn.style.cursor = 'pointer';
copyBtn.style.transition = 'all 0.2s';

copyBtn.onmouseover = () => copyBtn.style.transform = 'translateY(-2px)';
copyBtn.onmouseout = () => copyBtn.style.transform = 'none';

copyBtn.onclick = async () => {
    try {
        const text = document.getElementById('jigyou3Output').innerText;
        await navigator.clipboard.writeText(text);
        
        const originalText = copyBtn.innerText;
        const originalBg = copyBtn.style.background;
        copyBtn.innerText = "コピーしました！";
        copyBtn.style.background = '#10b981'; // Green
        
        setTimeout(() => {
            copyBtn.innerText = originalText;
            copyBtn.style.background = originalBg;
        }, 2000);
    } catch (e) {
        alert('コピーに失敗しました。ブラウザのセキュリティ設定をご確認ください。');
    }
};

jigyou3Wrapper.appendChild(copyBtn);
outputArea.scrollTop = outputArea.scrollHeight;
