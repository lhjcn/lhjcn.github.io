const { app, BrowserWindow, ipcMain } = require('electron');
const path = require('path');

function createWindow() {
    const win = new BrowserWindow({
        width: 350,
        height: 350,
        minWidth: 250,
        resizable: true, // 允许窗口大小调整
        transparent: true, // 设置窗口背景透明
        frame: false, // 可选：无边框窗口（如需完全透明效果）
        alwaysOnTop: true, // 窗口始终置顶
        webPreferences: {
            preload: path.join(__dirname, 'preload.js'),
            contextIsolation: true,
            enableRemoteModule: false,
            nodeIntegration: false
        }
    });
    win.setMenuBarVisibility(false); // 隐藏菜单栏
    win.loadFile(path.join(__dirname, 'echarts-clock-gauge.html'));

    // 设置窗口宽高比为
     win.setAspectRatio(1);

    // 监听关闭窗口请求
    ipcMain.on('close-window', () => {
        win.close();
    });
}

app.on('ready', createWindow);

app.on('window-all-closed', () => {
    if (process.platform !== 'darwin') {
        app.quit();
    }
});

app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
        createWindow();
    }
});