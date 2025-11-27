/**
 * Frontend JavaScript for Podcast Influence Tracker
 *
 * Handles interactive features for shortcodes
 */

(function() {
    'use strict';

    // Wait for DOM to be ready
    document.addEventListener('DOMContentLoaded', function() {
        initPodcastCards();
        initGuestCards();
    });

    /**
     * Initialize podcast cards
     */
    function initPodcastCards() {
        const cards = document.querySelectorAll('.pit-podcast-card');

        cards.forEach(card => {
            // Add click handler if needed
            card.addEventListener('click', function(e) {
                // Future: Could open modal with more details
            });
        });
    }

    /**
     * Initialize guest cards
     */
    function initGuestCards() {
        const cards = document.querySelectorAll('.pit-guest-card');

        cards.forEach(card => {
            // Add click handler if needed
            card.addEventListener('click', function(e) {
                // Future: Could open modal with guest details
            });
        });
    }

    /**
     * Fetch data from public API (for future dynamic loading)
     */
    function fetchFromAPI(endpoint) {
        if (typeof pitPublicData === 'undefined') {
            return Promise.reject('API URL not configured');
        }

        return fetch(pitPublicData.apiUrl + endpoint)
            .then(response => response.json());
    }

    // Export for potential use by themes
    window.PIT = {
        fetchFromAPI: fetchFromAPI
    };

})();
