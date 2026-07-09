// ***********************************************************
// This example support/index.js is processed and
// loaded automatically before your test files.
//
// This is a great place to put global configuration and
// behavior that modifies Cypress.
//
// You can change the location of this file or turn off
// automatically serving support files with the
// 'supportFile' configuration option.
//
// You can read more here:
// https://on.cypress.io/configuration
// ***********************************************************

// Import commands.js using ES2015 syntax:
import './commands'

// Alternatively you can use CommonJS syntax:
// require('./commands')

const atomCultureHeaders = headers => ({
  ...headers,
  'X-Atom-Culture': 'en',
})

Cypress.Commands.overwrite('visit', (originalFn, url, options = {}) => originalFn(url, {
  ...options,
  headers: atomCultureHeaders(options.headers),
}))

Cypress.Commands.overwrite('request', (originalFn, ...args) => {
  if (null !== args[0] && 'object' === typeof args[0]) {
    args[0] = {
      ...args[0],
      headers: atomCultureHeaders(args[0].headers),
    }
  } else if ('string' === typeof args[1]) {
    args = [{
      method: args[0],
      url: args[1],
      body: args[2],
      headers: atomCultureHeaders(),
    }]
  } else if ('string' === typeof args[0]) {
    args = [{
      url: args[0],
      headers: atomCultureHeaders(),
    }]
  }

  return originalFn(...args)
})
