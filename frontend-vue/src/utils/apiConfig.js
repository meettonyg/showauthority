import axios from 'axios'

/**
 * Shared API Configuration
 *
 * Provides common API configuration for all service files.
 * Centralizes WordPress REST API setup and nonce handling.
 *
 * @package ShowAuthority
 * @since 5.4.0
 */

/**
 * Get the WordPress REST API base URL
 * Checks multiple config object names used across different pages
 * @returns {string} The API base URL
 */
export function getApiBase() {
  const config = window.guestifyDetailData || window.pitConfig || window.guestifyData
  if (config?.restUrl) {
    return config.restUrl.replace(/\/$/, '')
  }
  return import.meta.env.VITE_API_URL || '/wp-json/guestify/v1'
}

/**
 * Get the WordPress REST API nonce
 * @returns {string} The nonce for authentication
 */
export function getNonce() {
  const config = window.guestifyDetailData || window.pitConfig || window.guestifyData
  return config?.nonce || ''
}

/**
 * Create a configured axios instance for API calls
 * @returns {AxiosInstance} Configured axios instance with nonce interceptor
 */
export function createApiClient() {
  const api = axios.create({
    headers: {
      'Content-Type': 'application/json'
    }
  })

  // Add nonce to all requests
  api.interceptors.request.use(config => {
    config.baseURL = getApiBase()
    const nonce = getNonce()
    if (nonce) {
      config.headers['X-WP-Nonce'] = nonce
    }
    return config
  })

  return api
}

// Default export for convenience
export default {
  getApiBase,
  getNonce,
  createApiClient
}
