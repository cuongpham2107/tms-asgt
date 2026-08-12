import { withAndroidManifest, withDangerousMod } from "@expo/config-plugins";
import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const networkSecurityConfig = `<?xml version="1.0" encoding="utf-8"?>
<network-security-config>
    <domain-config cleartextTrafficPermitted="true">
        <domain includeSubdomains="true">asgl.net.vn</domain>
        <trust-anchors>
            <certificates src="@raw/sectigo_r46" />
            <certificates src="@raw/sectigo_r36" />
            <certificates src="system" />
            <certificates src="user" />
        </trust-anchors>
    </domain-config>
    <base-config>
        <trust-anchors>
            <certificates src="system" />
            <certificates src="user" />
        </trust-anchors>
    </base-config>
</network-security-config>`;

function withNetworkSecurityConfig(config) {
  return withDangerousMod(config, ["android", async (config) => {
    const root = config.modRequest.platformProjectRoot;
    const resXmlDir = path.join(root, "app", "src", "main", "res", "xml");
    const resRawDir = path.join(root, "app", "src", "main", "res", "raw");

    // Create directories
    fs.mkdirSync(resXmlDir, { recursive: true });
    fs.mkdirSync(resRawDir, { recursive: true });

    // Write network_security_config.xml
    fs.writeFileSync(
      path.join(resXmlDir, "network_security_config.xml"),
      networkSecurityConfig
    );

    // Copy CA certificates to res/raw
    fs.copyFileSync(
      path.join(__dirname, "sectigo_r46.pem"),
      path.join(resRawDir, "sectigo_r46.pem")
    );
    fs.copyFileSync(
      path.join(__dirname, "sectigo_r36.pem"),
      path.join(resRawDir, "sectigo_r36.pem")
    );

    return config;
  }]);
}

function withAndroidConfig(config) {
  return withAndroidManifest(config, async (config) => {
    const manifest = config.modResults;

    const application = manifest.manifest.application?.[0];
    if (application) {
      application.$["android:allowBackup"] = "false";
      application.$["android:networkSecurityConfig"] = "@xml/network_security_config";
    }

    return config;
  });
}

export default function withAllowBackupAndSecurity(config) {
  config = withNetworkSecurityConfig(config);
  config = withAndroidConfig(config);
  return config;
}
