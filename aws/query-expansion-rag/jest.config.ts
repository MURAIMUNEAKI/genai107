import type { Config } from 'jest';

const config: Config = {
  testEnvironment: 'node',
  roots: ['<rootDir>', '<rootDir>/test'],
  testMatch: ['**/*.test.ts', '**/*.test.js'],
  transform: {
    '^.+\\.tsx?$': 'ts-jest',
  },
  moduleFileExtensions: ['ts', 'tsx', 'js', 'jsx', 'json', 'node'],
  testPathIgnorePatterns: ['/node_modules/', '/cdk.out/'],
  collectCoverageFrom: [
    'lib/**/*.ts',
    'custom-resources/**/*.js',
    '!lib/**/*.d.ts',
  ],
};

export default config;
