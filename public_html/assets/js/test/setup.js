import { vi } from 'vitest'
import { config } from '@vue/test-utils'

// Global test setup
config.global.mocks = {
  $t: (key) => key,
  $tc: (key, count) => `${key} (${count})`,
  $d: (date) => date,
  $n: (num) => num,
}

// Mock window.location
const mockLocation = {
  href: 'http://localhost:5173/',
  origin: 'http://localhost:5173',
  protocol: 'http:',
  host: 'localhost:5173',
  hostname: 'localhost',
  port: '5173',
  pathname: '/',
  search: '',
  hash: '',
  assign: vi.fn(),
  reload: vi.fn(),
  replace: vi.fn(),
  toString: () => 'http://localhost:5173/',
}

global.window = Object.create(window)
Object.defineProperty(window, 'location', {
  value: mockLocation,
  writable: true,
})

// Mock localStorage
const localStorageMock = {
  getItem: vi.fn(),
  setItem: vi.fn(),
  removeItem: vi.fn(),
  clear: vi.fn(),
  get length() {
    return 0
  },
  key: vi.fn(),
}

global.localStorage = localStorageMock

// Mock fetch
global.fetch = vi.fn()

// API base URL
global.API_BASE = process.env.VITE_API_BASE || '/api'
