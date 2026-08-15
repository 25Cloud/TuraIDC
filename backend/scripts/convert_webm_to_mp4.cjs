const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const ffmpegPath = require('@ffmpeg-installer/ffmpeg').path;
const mediaDir = path.resolve(__dirname, '..', 'public', 'media');

if (!ffmpegPath) {
  console.error('ffmpeg-static binary not found');
  process.exit(1);
}
console.log('ffmpeg:', ffmpegPath);

// Verify ffmpeg works
const versionCheck = spawnSync(ffmpegPath, ['-version'], { encoding: 'utf8', timeout: 10000 });
if (versionCheck.error) {
  console.error('ffmpeg execution error:', versionCheck.error.message);
  process.exit(1);
}
console.log(versionCheck.stdout.split('\n')[0]);

const files = fs.readdirSync(mediaDir).filter(f => f.toLowerCase().endsWith('.webm'));
console.log(`Found ${files.length} webm files`);

for (const file of files) {
  const inputPath = path.join(mediaDir, file);
  const mp4File = file.replace(/\.webm$/i, '.mp4');
  const outputPath = path.join(mediaDir, mp4File);

  if (fs.existsSync(outputPath)) {
    console.log(`SKIP ${file} -> ${mp4File} already exists`);
    continue;
  }

  console.log(`Converting ${file} -> ${mp4File} ...`);
  const result = spawnSync(ffmpegPath, [
    '-i', inputPath,
    '-c:v', 'libx264',
    '-c:a', 'aac',
    '-y', outputPath,
  ], { encoding: 'utf8', timeout: 120000 });

  if (result.error) {
    console.error(`FAILED: ${file}`, result.error.message);
  } else if (result.status !== 0) {
    console.error(`FAILED: ${file} exit code ${result.status}`);
    if (result.stderr) {
      const lines = result.stderr.split('\n');
      console.error(lines.slice(-5).join('\n'));
    }
  } else {
    console.log(`OK: ${mp4File} (${(fs.statSync(outputPath).size / 1024).toFixed(1)} KB)`);
  }
}

console.log('\nDone.');
