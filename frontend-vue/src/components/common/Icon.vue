<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    :stroke-width="strokeWidth"
    v-html="iconPath"
  ></svg>
</template>

<script setup>
/**
 * Icon Component
 *
 * Reusable SVG icon component that renders icons by name.
 * Centralizes icon definitions for easier maintenance.
 *
 * @package ShowAuthority
 * @since 5.4.0
 */

import { computed } from 'vue'

const props = defineProps({
  name: {
    type: String,
    required: true
  },
  size: {
    type: [Number, String],
    default: 14
  },
  strokeWidth: {
    type: [Number, String],
    default: 2
  }
})

// Icon path definitions (sanitized SVG paths only - no user input)
const icons = {
  wand: `
    <path d="M15 4V2"></path>
    <path d="M15 16v-2"></path>
    <path d="M8 9h2"></path>
    <path d="M20 9h2"></path>
    <path d="M17.8 11.8L19 13"></path>
    <path d="M15 9h0"></path>
    <path d="M17.8 6.2L19 5"></path>
    <path d="m3 21 9-9"></path>
    <path d="M12.2 6.2 11 5"></path>
  `,
  compress: `
    <path d="m15 9-6 6"></path>
    <path d="M18 6L6 18"></path>
    <path d="M21 3L3 21"></path>
    <path d="m4 8 4-4"></path>
    <path d="m16 20 4-4"></path>
  `,
  expand: `
    <path d="M21 11V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h6"></path>
    <path d="m12 12 4 10 1.7-4.3L22 16Z"></path>
  `,
  formal: `
    <rect x="2" y="6" width="20" height="12" rx="2"></rect>
    <path d="M22 10c-1.2.33-3 .33-4.2.33-1.2 0-3 0-4.2-.33"></path>
    <path d="M2 10c1.2.33 3 .33 4.2.33 1.2 0 3 0 4.2-.33"></path>
  `,
  casual: `
    <circle cx="12" cy="12" r="10"></circle>
    <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
    <line x1="9" y1="9" x2="9.01" y2="9"></line>
    <line x1="15" y1="9" x2="15.01" y2="9"></line>
  `,
  edit: `
    <path d="M12 20h9"></path>
    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
  `,
  mail: `
    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
    <polyline points="22,6 12,13 2,6"></polyline>
  `,
  send: `
    <line x1="22" y1="2" x2="11" y2="13"></line>
    <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
  `,
  layers: `
    <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
    <path d="M2 17l10 5 10-5"></path>
    <path d="M2 12l10 5 10-5"></path>
  `,
  copy: `
    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
  `,
  save: `
    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
    <polyline points="17 21 17 13 7 13 7 21"></polyline>
    <polyline points="7 3 7 8 15 8"></polyline>
  `,
  check: `
    <polyline points="20 6 9 17 4 12"></polyline>
  `,
  checkCircle: `
    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
    <polyline points="22 4 12 14.01 9 11.01"></polyline>
  `,
  trash: `
    <polyline points="3 6 5 6 21 6"></polyline>
    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
  `,
  play: `
    <polygon points="5 3 19 12 5 21 5 3"></polygon>
  `,
  externalLink: `
    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
    <polyline points="15 3 21 3 21 9"></polyline>
    <line x1="10" y1="14" x2="21" y2="3"></line>
  `,
  chevronDown: `
    <polyline points="6 9 12 15 18 9"></polyline>
  `,
  chevronUp: `
    <polyline points="18 15 12 9 6 15"></polyline>
  `,
  eye: `
    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
    <circle cx="12" cy="12" r="3"></circle>
  `,
  eyeOff: `
    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
    <line x1="1" y1="1" x2="23" y2="23"></line>
  `,
  x: `
    <line x1="18" y1="6" x2="6" y2="18"></line>
    <line x1="6" y1="6" x2="18" y2="18"></line>
  `,
  alertCircle: `
    <circle cx="12" cy="12" r="10"></circle>
    <line x1="12" y1="8" x2="12" y2="12"></line>
    <line x1="12" y1="16" x2="12.01" y2="16"></line>
  `
}

const iconPath = computed(() => {
  return icons[props.name] || icons.edit
})
</script>
