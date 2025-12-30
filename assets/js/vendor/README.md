# Vendor JavaScript Libraries

This directory contains third-party JavaScript libraries bundled with the plugin.

## Files Required

Download these files and place them in this directory:

1. **vue.global.prod.js** (Vue 3.3.4)
   - Source: https://unpkg.com/vue@3.3.4/dist/vue.global.prod.js

2. **vue-demi.iife.js** (Vue-Demi 0.14.6)
   - Source: https://unpkg.com/vue-demi@0.14.6/lib/index.iife.js

3. **pinia.iife.js** (Pinia 2.1.7)
   - Source: https://unpkg.com/pinia@2.1.7/dist/pinia.iife.js

## Quick Download Commands

```bash
curl -o vue.global.prod.js "https://unpkg.com/vue@3.3.4/dist/vue.global.prod.js"
curl -o vue-demi.iife.js "https://unpkg.com/vue-demi@0.14.6/lib/index.iife.js"
curl -o pinia.iife.js "https://unpkg.com/pinia@2.1.7/dist/pinia.iife.js"
```

## Why Bundle Locally?

Loading scripts from third-party CDNs introduces:
- **Availability risks**: If the CDN is down, your features break
- **Performance risks**: CDN latency can slow page loads
- **Security risks**: If the CDN is compromised, your users are at risk

Bundling locally ensures consistent, secure, and fast delivery of dependencies.
