/**
 * Shared API Utility
 *
 * Provides a consistent interface for making API calls across Vue applications.
 * Centralizes header handling, error management, and authentication.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.4.0
 */

(function(global) {
    'use strict';

    /**
     * Create an API client with the given configuration
     *
     * @param {Object} config - Configuration object
     * @param {string} config.restUrl - Base REST API URL
     * @param {string} config.nonce - WordPress REST API nonce
     * @returns {Object} API client with request methods
     */
    function createApiClient(config) {
        const restUrl = config.restUrl || '';
        const nonce = config.nonce || '';

        /**
         * Make an API request
         *
         * @param {string} endpoint - API endpoint (relative to restUrl)
         * @param {Object} options - Fetch options
         * @returns {Promise<Object>} Parsed JSON response
         * @throws {Error} If request fails
         */
        async function request(endpoint, options = {}) {
            const url = restUrl + endpoint;

            const response = await fetch(url, {
                ...options,
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                    ...options.headers,
                },
            });

            if (!response.ok) {
                let errorMessage = 'API request failed';
                try {
                    const error = await response.json();
                    errorMessage = error.message || errorMessage;
                } catch {
                    // Response wasn't JSON, use default message
                }
                throw new Error(errorMessage);
            }

            return response.json();
        }

        /**
         * Make a GET request
         *
         * @param {string} endpoint - API endpoint
         * @param {Object} options - Additional fetch options
         * @returns {Promise<Object>} Parsed JSON response
         */
        async function get(endpoint, options = {}) {
            return request(endpoint, { ...options, method: 'GET' });
        }

        /**
         * Make a POST request
         *
         * @param {string} endpoint - API endpoint
         * @param {Object} data - Request body data
         * @param {Object} options - Additional fetch options
         * @returns {Promise<Object>} Parsed JSON response
         */
        async function post(endpoint, data = {}, options = {}) {
            return request(endpoint, {
                ...options,
                method: 'POST',
                body: JSON.stringify(data),
            });
        }

        /**
         * Make a PATCH request
         *
         * @param {string} endpoint - API endpoint
         * @param {Object} data - Request body data
         * @param {Object} options - Additional fetch options
         * @returns {Promise<Object>} Parsed JSON response
         */
        async function patch(endpoint, data = {}, options = {}) {
            return request(endpoint, {
                ...options,
                method: 'PATCH',
                body: JSON.stringify(data),
            });
        }

        /**
         * Make a DELETE request
         *
         * @param {string} endpoint - API endpoint
         * @param {Object} options - Additional fetch options
         * @returns {Promise<Object>} Parsed JSON response
         */
        async function del(endpoint, options = {}) {
            return request(endpoint, { ...options, method: 'DELETE' });
        }

        return {
            request,
            get,
            post,
            patch,
            delete: del,
        };
    }

    // Export to global scope for use in Vue applications
    global.GuestifyApi = {
        createClient: createApiClient,
    };

})(typeof window !== 'undefined' ? window : this);
