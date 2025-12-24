# Vitest Test Suite

This directory contains the Vitest test suite for the Compliant Content Publisher plugin's TypeScript/React components.

> **Note**: PHP unit and integration tests are documented in [../README.md](../README.md)

## Test Structure

```
tests/src/
├── vitest.setup.ts          # Global test setup and mocks
├── utils.test.ts            # Tests for utility functions
├── constants.test.ts        # Tests for constants
├── types.test.ts            # Tests for TypeScript types
├── actions.test.tsx         # Tests for action modals
└── api/
    └── diff.test.ts         # Tests for diff API functions
```

## Running Tests

### Run all tests
```bash
npm run test:js
```

### Run tests in watch mode
```bash
npm run test:js:watch
```

### Run tests with coverage
```bash
npm run test:js:coverage
```

### Run specific test file
```bash
npx vitest tests/src/utils.test.ts
```

### Run tests matching a pattern
```bash
npx vitest --grep "formatDate"
```

## Test Coverage

The test suite covers:

### ✅ Utility Functions (`utils.test.ts` - 85+ tests)
- `formatDate()` - Date formatting
- `formatDateTime()` - Date/time formatting
- `isValidPost()` - Post validation
- `sanitizePosts()` - Post array sanitization
- `searchPosts()` - Post search functionality
- `sortPosts()` - Post sorting (by date, title, ID)
- `paginatePosts()` - Pagination logic
- `getPaginationInfo()` - Pagination metadata
- `extractUrlPath()` - URL path extraction

### ✅ API Functions (`api/diff.test.ts` - 25+ tests)
- `fetchDiffPreview()` - Fetching diff previews
  - Successful requests
  - Error handling
  - Network errors
  - Payload validation
- `updatePostContent()` - Updating post content
  - Successful updates
  - Error responses
  - Nonce handling
  - Optional parameters
  - Invalid JSON handling

### ✅ Constants (`constants.test.ts` - 10+ tests)
- Layout constants (table, grid, list)
- Sorting directions
- Sort arrows and values
- Sort labels and icons

### ✅ TypeScript Types (`types.test.ts` - 15+ tests)
- `Post` interface validation
- `ImportSession` interface validation
- `ImportLog` interface validation
- `PaginationInfo` interface validation
- Type safety checks

### ✅ Actions (`actions.test.tsx` - 15+ tests)
- Draft action modal
- Bulk import action modal
- Update action modal
- Post diff action modal
- Error handling for invalid selections

## Configuration

### vitest.config.mts

```typescript
{
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src/')  // Allows @/ imports
    }
  },
  test: {
    environment: 'happy-dom',              // DOM environment for React tests
    setupFiles: ['./tests/src/vitest.setup.ts'],
    globals: true,
    coverage: {
      provider: 'v8',
      reporter: ['text', 'html', 'clover', 'json'],
      include: ['src/**/*.{ts,tsx}'],
      exclude: ['src/**/*.test.{ts,tsx}', 'src/index.tsx']
    }
  }
}
```

### Test Setup (vitest.setup.ts)

The setup file:
- Imports `@testing-library/jest-dom` matchers
- Sets up `cleanup()` to run after each test
- Mocks `window.ccpAdminData` global object
- Provides default WordPress environment

## Writing Tests

### Basic Test Structure

```typescript
import { describe, expect, it } from 'vitest';
import { myFunction } from '@/utils';

describe('myFunction', () => {
  it('should do something', () => {
    const result = myFunction('input');
    expect(result).toBe('expected');
  });
});
```

### Testing React Components

```typescript
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import MyComponent from '@/components/MyComponent';

describe('MyComponent', () => {
  it('should render correctly', () => {
    render(<MyComponent />);
    expect(screen.getByText('Hello')).toBeInTheDocument();
  });
});
```

### Mocking fetch API

```typescript
import { describe, expect, it, vi, beforeEach } from 'vitest';

describe('API function', () => {
  beforeEach(() => {
    global.fetch = vi.fn();
  });

  it('should make API call', async () => {
    (global.fetch as any).mockResolvedValue({
      ok: true,
      json: async () => ({ data: 'test' })
    });

    const result = await myApiFunction();
    expect(result).toEqual({ data: 'test' });
  });
});
```

## Test Utilities

### Available Testing Library Queries

- `getByText` - Find element by text content
- `getByRole` - Find element by ARIA role
- `getByLabelText` - Find element by label
- `getByTestId` - Find element by test ID
- `queryBy*` - Same as `getBy*` but returns null instead of throwing
- `findBy*` - Async version of `getBy*`

### Custom Matchers

From `@testing-library/jest-dom`:
- `toBeInTheDocument()` - Element is in the DOM
- `toBeVisible()` - Element is visible
- `toHaveTextContent()` - Element has text content
- `toHaveAttribute()` - Element has attribute
- `toBeDisabled()` - Element is disabled
- `toHaveClass()` - Element has CSS class

## Coverage Goals

Target coverage metrics:
- **Statements**: 80%+
- **Branches**: 75%+
- **Functions**: 80%+
- **Lines**: 80%+

### Excluded from Coverage
- Entry point files (`index.tsx`, `import-history.tsx`, `admin-tools.tsx`)
- Test files
- Build artifacts

## Common Issues

### Issue: Import alias not resolving
**Solution**: Check that `vitest.config.mts` has the correct alias configuration and that you're using `@/` prefix.

### Issue: DOM methods not available
**Solution**: Ensure `environment: 'happy-dom'` is set in vitest config.

### Issue: TypeScript errors in tests
**Solution**: Make sure TypeScript types are correctly imported and `tsconfig.json` includes the test directory.

### Issue: Tests fail with "Cannot find module"
**Solution**: Check that the module path is correct and uses the `@/` alias. Ensure the file exists in `src/`.

## Best Practices

1. **Test file naming**: Use `.test.ts` or `.test.tsx` suffix
2. **Test organization**: Group related tests with `describe()` blocks
3. **Test descriptions**: Use clear, descriptive test names starting with "should"
4. **Assertions**: Each test should have at least one assertion
5. **Mocking**: Mock external dependencies (fetch, WordPress globals)
6. **Isolation**: Tests should not depend on each other
7. **Cleanup**: Use `afterEach()` to clean up after tests
8. **Coverage**: Aim for high coverage but focus on meaningful tests

## CI/CD Integration

Tests run automatically on:
- Pre-commit (via husky)
- Pull requests
- Main branch pushes

### GitHub Actions Example

```yaml
- name: Run Tests
  run: npm run test:js

- name: Generate Coverage
  run: npm run test:js:coverage

- name: Upload Coverage
  uses: codecov/codecov-action@v3
  with:
    files: ./coverage/vitest/clover.xml
```

## Resources

- [Vitest Documentation](https://vitest.dev/)
- [Testing Library Documentation](https://testing-library.com/)
- [Jest DOM Matchers](https://github.com/testing-library/jest-dom)
- [Happy DOM](https://github.com/capricorn86/happy-dom)

## Troubleshooting

### Debug a specific test
```bash
npx vitest --inspect-brk tests/src/utils.test.ts
```

### Run tests with verbose output
```bash
npx vitest --reporter=verbose
```

### Clear cache and run tests
```bash
npx vitest --clearCache && npm run test:js
```

## Contributing

When adding new functionality:
1. Write tests alongside your code
2. Ensure all tests pass: `npm run test:js`
3. Check coverage: `npm run test:js:coverage`
4. Add tests to cover edge cases
5. Update this README if adding new test categories
