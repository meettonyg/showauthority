# Podcast Influence Tracker - Vue 3 Frontend

Headless Vue 3 frontend for the Podcast Influence Tracker WordPress plugin.

## Features

- 🎙️ Browse podcasts with search and pagination
- 👤 Guest directory (coming soon)
- 📊 Podcast metrics display (coming soon)
- 🔍 Real-time search
- 📱 Responsive design
- ⚡ Fast and modern (Vite + Vue 3)

## Tech Stack

- **Vue 3** - Progressive JavaScript framework
- **Vue Router** - Official routing
- **Pinia** - State management
- **Vite** - Build tool
- **Axios** - HTTP client

## Prerequisites

- Node.js 16+ and npm
- WordPress site with Podcast Influence Tracker plugin installed

## Setup

### 1. Install Dependencies

```bash
cd frontend-vue
npm install
```

### 2. Configure API URL

Create a `.env` file in the `frontend-vue` directory:

```env
VITE_API_URL=https://your-wordpress-site.com/wp-json/podcast-influence/v1/public
```

Or update `vite.config.js` proxy target to point to your WordPress site.

### 3. Run Development Server

```bash
npm run dev
```

The app will be available at `http://localhost:3000`

### 4. Build for Production

```bash
npm run build
```

Built files will be in the `dist/` directory.

## Deployment Options

### Option 1: Vercel (Recommended)

1. Push code to GitHub
2. Import project in Vercel
3. Set environment variable: `VITE_API_URL=https://your-wp-site.com/wp-json/podcast-influence/v1/public`
4. Deploy!

### Option 2: Netlify

1. Push code to GitHub
2. Import project in Netlify
3. Build command: `npm run build`
4. Publish directory: `dist`
5. Set environment variable: `VITE_API_URL`

### Option 3: Static Hosting

Build locally and upload `dist/` folder to any static hosting:
- AWS S3 + CloudFront
- DigitalOcean Spaces
- GitHub Pages
- Your own server

## Project Structure

```
frontend-vue/
├── src/
│   ├── components/       # Reusable Vue components
│   │   └── PodcastCard.vue
│   ├── views/           # Page components
│   │   ├── Home.vue
│   │   ├── Podcasts.vue
│   │   ├── PodcastDetail.vue
│   │   ├── Guests.vue
│   │   └── GuestDetail.vue
│   ├── stores/          # Pinia stores
│   │   └── podcasts.js
│   ├── services/        # API services
│   │   └── api.js
│   ├── router/          # Vue Router config
│   │   └── index.js
│   ├── App.vue          # Root component
│   ├── main.js          # Entry point
│   └── style.css        # Global styles
├── index.html           # HTML template
├── vite.config.js       # Vite configuration
├── package.json
└── README.md
```

## API Endpoints Used

This frontend consumes the public REST API from the WordPress plugin:

- `GET /podcasts` - List podcasts
- `GET /podcasts/{id}` - Get podcast details
- `GET /podcasts/{id}/episodes` - Get podcast episodes
- `GET /podcasts/{id}/metrics` - Get social metrics
- `GET /guests` - List guests
- `GET /guests/{id}` - Get guest details
- `GET /search?q={query}&type={type}` - Search

## Development

### Adding a New Page

1. Create component in `src/views/`
2. Add route in `src/router/index.js`
3. Add navigation link in `src/App.vue`

### Adding a New Feature

1. Create Pinia store in `src/stores/` if needed
2. Add API methods in `src/services/api.js`
3. Create components in `src/components/`
4. Use in views

## CORS Configuration

If you encounter CORS errors, add this to your WordPress `wp-config.php`:

```php
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
```

Or install a WordPress CORS plugin for development.

## Environment Variables

- `VITE_API_URL` - WordPress REST API base URL

## License

Same as parent plugin (GPL v2 or later)
