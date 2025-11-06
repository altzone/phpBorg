# phpBorg 2.0 - Frontend

Modern Vue.js 3 frontend for phpBorg backup management system.

## Tech Stack

- **Vue.js 3** - Progressive JavaScript framework (Composition API)
- **Vite** - Next generation frontend tooling
- **Vue Router 4** - Official router for Vue.js
- **Pinia** - Intuitive state management for Vue
- **Axios** - Promise based HTTP client
- **TailwindCSS 3** - Utility-first CSS framework

## Features

- ✓ JWT Authentication with automatic token refresh
- ✓ Role-based access control (Admin, Operator, Viewer)
- ✓ Responsive design with TailwindCSS
- ✓ Protected routes with navigation guards
- ✓ API proxy configuration for development
- ✓ Modern component-based architecture

## Prerequisites

- Node.js 18+ and npm (or yarn/pnpm)
- Running API backend on http://localhost:8080

## Installation

```bash
# Install dependencies
npm install

# Or with yarn
yarn install

# Or with pnpm
pnpm install
```

## Development

```bash
# Start dev server (http://localhost:5173)
npm run dev
```

The dev server includes:
- Hot Module Replacement (HMR)
- API proxy to backend (http://localhost:8080)
- CORS handling

## Build for Production

```bash
# Build for production
npm run build

# Preview production build
npm run preview
```

The built files will be in the `dist/` directory.

## Project Structure

```
frontend/
├── src/
│   ├── assets/          # Static assets (CSS, images)
│   │   └── main.css     # Global styles with Tailwind
│   ├── layouts/         # Layout components
│   │   └── DashboardLayout.vue
│   ├── views/           # Page components
│   │   ├── LoginView.vue
│   │   ├── DashboardView.vue
│   │   ├── ServersView.vue
│   │   ├── BackupsView.vue
│   │   ├── JobsView.vue
│   │   └── SettingsView.vue
│   ├── stores/          # Pinia stores
│   │   └── auth.js      # Authentication state
│   ├── services/        # API services
│   │   ├── api.js       # Axios instance with interceptors
│   │   └── auth.js      # Authentication API calls
│   ├── router/          # Vue Router configuration
│   │   └── index.js     # Routes and navigation guards
│   ├── App.vue          # Root component
│   └── main.js          # Application entry point
├── index.html           # HTML entry point
├── vite.config.js       # Vite configuration
├── tailwind.config.js   # Tailwind configuration
└── package.json         # Dependencies
```

## Authentication Flow

1. User logs in with username/password
2. API returns JWT access token (15min) and refresh token (7 days)
3. Tokens stored in localStorage
4. Axios interceptor adds Bearer token to all requests
5. On 401 error, automatically refresh token
6. If refresh fails, redirect to login

## Default Credentials

- **Username:** `admin`
- **Password:** `admin123`

**⚠️ CHANGE THESE IN PRODUCTION!**

## API Configuration

The frontend expects the API to be available at:
- Development: Proxied through Vite (`/api` → `http://localhost:8080/api`)
- Production: Same origin or configure in `vite.config.js`

## Available Routes

| Route | Description | Auth Required | Role Required |
|-------|-------------|---------------|---------------|
| `/login` | Login page | No | - |
| `/` | Dashboard | Yes | - |
| `/servers` | Servers list | Yes | - |
| `/servers/:id` | Server details | Yes | - |
| `/backups` | Backups list | Yes | - |
| `/jobs` | Jobs monitoring | Yes | - |
| `/settings` | System settings | Yes | ROLE_ADMIN |

## Phase 1 Status

✅ **Completed:**
- JWT Authentication system
- Login page with error handling
- Dashboard layout with navbar
- Protected routes with role checking
- Automatic token refresh
- Responsive design with TailwindCSS

🚧 **Coming in Phase 2-7:**
- Servers management (Phase 2)
- Queue system integration (Phase 3)
- Real-time updates with SSE (Phase 4)
- Backups management (Phase 5)
- Settings & configuration (Phase 6)
- Alert notifications (Phase 7)

## Troubleshooting

### API Connection Issues

If you see CORS errors or API connection issues:

1. Check API is running: `curl http://localhost:8080/api/auth/login`
2. Verify nginx configuration includes CORS headers
3. Check Vite proxy configuration in `vite.config.js`

### Token Issues

If authentication keeps failing:

1. Clear localStorage: `localStorage.clear()`
2. Check browser console for errors
3. Verify API returns valid JWT tokens

## License

Same as phpBorg main project.
