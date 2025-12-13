/**
 * Shared formatting utility functions
 *
 * @package Podcast_Influence_Tracker
 * @since 4.3.0
 */

/**
 * Format a number for display (e.g., 1500 -> "1.5K", 1500000 -> "1.5M")
 *
 * @param {number} num - The number to format
 * @returns {string} Formatted number string
 */
export function formatNumber(num) {
  if (!num) return '0'
  if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M'
  if (num >= 1000) return (num / 1000).toFixed(1) + 'K'
  return num.toString()
}

/**
 * Format a date string for display
 *
 * @param {string} dateStr - Date string to format
 * @param {Object} options - Intl.DateTimeFormat options
 * @returns {string} Formatted date string
 */
export function formatDate(dateStr, options = {}) {
  if (!dateStr) return ''
  const date = new Date(dateStr)

  const defaultOptions = {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    ...options
  }

  return date.toLocaleDateString('en-US', defaultOptions)
}

/**
 * Format a date string with short month format
 *
 * @param {string} dateStr - Date string to format
 * @returns {string} Formatted date string
 */
export function formatDateShort(dateStr) {
  return formatDate(dateStr, { month: 'short' })
}

/**
 * Get initials from a full name
 *
 * @param {string} name - Full name
 * @returns {string} Initials (up to 2 characters)
 */
export function getInitials(name) {
  const parts = (name || '').trim().split(' ')
  if (parts.length >= 2) {
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
  }
  return (name || '').substring(0, 2).toUpperCase()
}

/**
 * Truncate text to a specified length
 *
 * @param {string} text - Text to truncate
 * @param {number} length - Maximum length
 * @returns {string} Truncated text with ellipsis if needed
 */
export function truncate(text, length) {
  if (!text || text.length <= length) return text
  return text.substring(0, length) + '...'
}

/**
 * Format duration in seconds to human readable format
 *
 * @param {number} seconds - Duration in seconds
 * @returns {string} Formatted duration (e.g., "1h 30m")
 */
export function formatDuration(seconds) {
  if (!seconds) return ''
  const mins = Math.floor(seconds / 60)
  const secs = seconds % 60
  if (mins >= 60) {
    const hrs = Math.floor(mins / 60)
    const remainMins = mins % 60
    return `${hrs}h ${remainMins}m`
  }
  return `${mins}m ${secs}s`
}
