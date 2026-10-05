const { withDangerousMod } = require("@expo/config-plugins");
const fs = require("fs");
const path = require("path");

const fmtSnippet = `
    installer.pods_project.targets.each do |target|
      if target.name == 'fmt'
        target.build_configurations.each do |config|
          config.build_settings['CLANG_CXX_LANGUAGE_STANDARD'] = 'c++17'
        end
      end
    end
`;

function withIosFmtCxx17(config) {
  return withDangerousMod(config, [
    "ios",
    async (config) => {
      const podfilePath = path.join(config.modRequest.platformProjectRoot, "Podfile");
      if (!fs.existsSync(podfilePath)) return config;

      let podfileContent = fs.readFileSync(podfilePath, "utf-8");

      if (!podfileContent.includes("target.name == 'fmt'")) {
        podfileContent = podfileContent.replace(
          /post_install do \|installer\|/,
          `post_install do |installer|${fmtSnippet}`
        );
        fs.writeFileSync(podfilePath, podfileContent);
      }

      return config;
    },
  ]);
}

module.exports = withIosFmtCxx17;
