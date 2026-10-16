#!/usr/bin/env node

/**
 * WAAMINI MCP Server - Direct Launcher
 * Runs @benborla29/mcp-server-mysql with proper stdio connection
 */

const path = require('path');
const { spawn } = require('child_process');
require('dotenv').config();

console.log('╔════════════════════════════════════════════════════════════════╗');
console.log('║         WAAMINI MCP SERVER - STARTING                          ║');
console.log('║              MySQL Model Context Protocol Server               ║');
console.log('╚════════════════════════════════════════════════════════════════╝\n');

console.log('📦 MCP Package: @benborla29/mcp-server-mysql v2.0.9');
console.log('🗄️  Database: waamini (MySQL)');
console.log('🔐 User: root @ 127.0.0.1:3306\n');

// Configuration
const env = {
    ...process.env,
    MYSQL_HOST: process.env.DB_HOST || '127.0.0.1',
    MYSQL_PORT: process.env.DB_PORT || '3306',
    MYSQL_USER: process.env.DB_USER || 'root',
    MYSQL_PASSWORD: process.env.DB_PASS || '',
    MYSQL_DATABASE: process.env.DB_NAME || 'waamini',
    ALLOW_INSERT_OPERATION: 'true',
    ALLOW_UPDATE_OPERATION: 'true',
    ALLOW_DELETE_OPERATION: 'true'
};

console.log('🔧 Configuration:');
console.log(`   MYSQL_HOST: ${env.MYSQL_HOST}`);
console.log(`   MYSQL_PORT: ${env.MYSQL_PORT}`);
console.log(`   MYSQL_USER: ${env.MYSQL_USER}`);
console.log(`   MYSQL_DATABASE: ${env.MYSQL_DATABASE}`);
console.log(`   Write Operations: ENABLED\n`);

console.log('═'.repeat(65) + '\n');

// Start MCP server using direct path
const mcpPath = path.join(__dirname, 'node_modules', '@benborla29', 'mcp-server-mysql', 'dist', 'index.js');

console.log(`🚀 Launching MCP Server...`);
console.log(`   Command: node ${mcpPath}\n`);

const mcpProcess = spawn('node', [mcpPath], {
    cwd: __dirname,
    env: env,
    stdio: ['inherit', 'inherit', 'inherit']
});

// Handle process events
mcpProcess.on('error', (err) => {
    console.error('❌ Failed to start MCP server:', err);
    process.exit(1);
});

mcpProcess.on('exit', (code, signal) => {
    console.log(`\n❌ MCP Server stopped (exit code: ${code}, signal: ${signal})`);
    process.exit(code);
});

// Handle parent process termination
process.on('SIGINT', () => {
    console.log('\n⏹️  Shutting down MCP server...');
    mcpProcess.kill('SIGTERM');
});

process.on('SIGTERM', () => {
    console.log('\n⏹️  Shutting down MCP server...');
    mcpProcess.kill('SIGTERM');
});

console.log('✅ MCP Server is running...');
console.log('   Press Ctrl+C to stop\n');
console.log('═'.repeat(65));
