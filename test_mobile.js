const { chromium } = require('playwright-core');
(async () => {
  const browser = await chromium.launch({ channel: 'chrome' });
  const page = await browser.newPage({ viewport: { width: 375, height: 812 } });
  await page.goto('http://localhost:8080/waamini/index.php');
  
  const display = await page.evaluate(() => {
    const el = document.getElementById('navToggle');
    if (!el) return 'Element not found';
    const style = window.getComputedStyle(el);
    return {
      display: style.display,
      width: style.width,
      height: style.height,
      visibility: style.visibility,
      opacity: style.opacity,
      html: el.outerHTML
    };
  });
  console.log('Nav Toggle Style:', display);
  await browser.close();
})();
