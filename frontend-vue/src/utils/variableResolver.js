/**
 * Variable Resolution Utilities
 *
 * Handles resolving template variables with actual values from the API.
 * Works with the get_appearance_variables API response structure.
 *
 * @package ShowAuthority
 * @since 5.4.0
 */

/**
 * Flatten the categorized variable structure from get_appearance_variables API
 *
 * API Response Shape:
 * {
 *   "categories": [
 *     {
 *       "name": "Messaging & Positioning",
 *       "variables": [
 *         { "tag": "{{authority_hook}}", "label": "Your Authority Hook", "value": "..." }
 *       ]
 *     }
 *   ]
 * }
 *
 * @param {object} apiResponse - Response from get_appearance_variables
 * @returns {object} Flat map of { variable_name: value }
 */
export function flattenVariables(apiResponse) {
  const flat = {}

  if (!apiResponse?.categories) {
    return flat
  }

  // Iterate over each category
  apiResponse.categories.forEach(category => {
    if (!category.variables) return

    // Iterate over variables within each category
    category.variables.forEach(variable => {
      // Extract key from tag: "{{authority_hook}}" -> "authority_hook"
      const tag = variable.tag || ''
      const key = tag.replace(/^\{\{|\}\}$/g, '')

      if (key && variable.value !== undefined) {
        flat[key] = variable.value
      }
    })
  })

  return flat
}

/**
 * Resolve template variables with actual values
 *
 * Replaces {{variable_name}} placeholders with their corresponding values.
 * If a variable has no value, the placeholder is left unchanged.
 *
 * @param {string} text - Text containing {{variable}} placeholders
 * @param {object} apiResponse - Variable data from get_appearance_variables API
 * @returns {string} Resolved text with variables replaced
 *
 * @example
 * const text = "Hi {{host_name}}, I loved your podcast {{podcast_name}}!"
 * const resolved = resolveVariables(text, variablesData)
 * // Returns: "Hi John Smith, I loved your podcast The Business Show!"
 */
export function resolveVariables(text, apiResponse) {
  if (!text) return ''
  let resolved = text

  // Build flat map from API's categorized structure
  const flatVars = flattenVariables(apiResponse)

  Object.entries(flatVars).forEach(([key, value]) => {
    // Skip if value is empty/null
    if (value === null || value === undefined || value === '') {
      return
    }

    // Match {{key}} format (double braces)
    const doubleBracePattern = new RegExp(`\\{\\{${escapeRegExp(key)}\\}\\}`, 'gi')
    resolved = resolved.replace(doubleBracePattern, value)

    // Also match {key} format (single braces) for legacy templates
    const singleBracePattern = new RegExp(`\\{${escapeRegExp(key)}\\}`, 'gi')
    resolved = resolved.replace(singleBracePattern, value)
  })

  return resolved
}

/**
 * Get list of unresolved variables in text
 * Useful for showing warnings about missing data
 *
 * @param {string} text - Text to check for unresolved variables
 * @param {object} apiResponse - Variable data from get_appearance_variables API
 * @returns {string[]} List of unresolved variable names
 *
 * @example
 * const unresolved = getUnresolvedVariables("Hi {{missing_var}}!", variablesData)
 * // Returns: ["missing_var"]
 */
export function getUnresolvedVariables(text, apiResponse) {
  if (!text) return []

  const flatVars = flattenVariables(apiResponse)

  // Find all {{variable}} patterns
  const matches = text.match(/\{\{(\w+)\}\}/g) || []

  return matches
    .map(match => match.replace(/^\{\{|\}\}$/g, ''))
    .filter(key => !flatVars[key] || flatVars[key] === '')
}

/**
 * Get all variable placeholders found in text
 *
 * @param {string} text - Text to scan for variables
 * @returns {string[]} List of variable names found (without braces)
 */
export function extractVariables(text) {
  if (!text) return []

  const matches = text.match(/\{\{(\w+)\}\}/g) || []

  return [...new Set(
    matches.map(match => match.replace(/^\{\{|\}\}$/g, ''))
  )]
}

/**
 * Check if text contains any template variables
 *
 * @param {string} text - Text to check
 * @returns {boolean} True if text contains {{variable}} patterns
 */
export function hasVariables(text) {
  if (!text) return false
  return /\{\{\w+\}\}/.test(text)
}

/**
 * Create a preview of text with variables highlighted
 * Useful for showing users which parts will be replaced
 *
 * @param {string} text - Text containing variables
 * @param {object} apiResponse - Variable data
 * @returns {object[]} Array of segments with { text, isVariable, resolved }
 */
export function getVariableSegments(text, apiResponse) {
  if (!text) return []

  const segments = []
  const flatVars = flattenVariables(apiResponse)
  const pattern = /(\{\{\w+\}\})/g
  let lastIndex = 0
  let match

  while ((match = pattern.exec(text)) !== null) {
    // Add text before the match
    if (match.index > lastIndex) {
      segments.push({
        text: text.substring(lastIndex, match.index),
        isVariable: false,
        resolved: null
      })
    }

    // Add the variable
    const varName = match[1].replace(/^\{\{|\}\}$/g, '')
    const value = flatVars[varName]

    segments.push({
      text: match[1],
      isVariable: true,
      resolved: value || null,
      variableName: varName
    })

    lastIndex = pattern.lastIndex
  }

  // Add remaining text
  if (lastIndex < text.length) {
    segments.push({
      text: text.substring(lastIndex),
      isVariable: false,
      resolved: null
    })
  }

  return segments
}

/**
 * Escape special regex characters in a string
 *
 * @param {string} string - String to escape
 * @returns {string} Escaped string safe for use in regex
 */
function escapeRegExp(string) {
  return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

export default {
  resolveVariables,
  flattenVariables,
  getUnresolvedVariables,
  extractVariables,
  hasVariables,
  getVariableSegments
}
