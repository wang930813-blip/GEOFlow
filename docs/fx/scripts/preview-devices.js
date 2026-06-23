const fs = require("fs");
const path = require("path");
const { chromium } = require("playwright");

const root = path.resolve(__dirname, "..");
const configPath = process.argv[2]
  ? path.resolve(process.argv[2])
  : path.join(root, "config", "device-preview.json");
const config = JSON.parse(fs.readFileSync(configPath, "utf8"));
const outDir = path.join(root, "output", "device-preview");
fs.mkdirSync(outDir, { recursive: true });

const chromePath = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome";

async function main() {
  const browser = await chromium.launch({
    headless: true,
    executablePath: fs.existsSync(chromePath) ? chromePath : undefined
  });
  const results = [];

  for (const pageConfig of config.pages) {
    const url = "file://" + path.join(root, pageConfig.path);
    for (const device of config.devices) {
      const page = await browser.newPage({
        viewport: { width: device.width, height: device.height },
        deviceScaleFactor: device.deviceScaleFactor || 1,
        isMobile: !!device.isMobile
      });
      await page.goto(url, { waitUntil: "networkidle" });
      await page.screenshot({
        path: path.join(outDir, `${pageConfig.name}-${device.name}.png`),
        fullPage: true
      });
      const metrics = await page.evaluate(() => ({
        innerWidth,
        scrollWidth: document.documentElement.scrollWidth,
        bodyScrollWidth: document.body.scrollWidth,
        hasHorizontalOverflow:
          document.documentElement.scrollWidth > window.innerWidth + 2 ||
          document.body.scrollWidth > window.innerWidth + 2
      }));
      results.push({ page: pageConfig.name, device: device.name, ...metrics });
      await page.close();
    }
  }

  await browser.close();
  fs.writeFileSync(
    path.join(outDir, "results.json"),
    JSON.stringify(results, null, 2)
  );

  const failures = results.filter((r) => r.hasHorizontalOverflow);
  console.table(results);
  if (failures.length) {
    console.error("Horizontal overflow detected:", failures);
    process.exit(1);
  }
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
