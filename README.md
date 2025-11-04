# phpBorg 2.0

🚀 **Professional PHP 8.3+ Frontend for BorgBackup** - Secure, efficient, and modern backup management system

[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--3.0-green)](LICENSE)

## ✨ Features

### 🎯 Core Features
- **Modern PHP 8.3+**: Strict typing, readonly properties, enums, and latest PHP features
- **PSR-4 Autoloading**: Clean namespace organization with Composer
- **Dependency Injection**: Professional DI container pattern
- **Security First**: No SQL injection, secure password hashing, encrypted credentials
- **Type Safety**: Full type hints and return types throughout the codebase

### 💾 Backup Support
- **Filesystem Backups**: Complete system backup with exclusion patterns
- **MySQL**: Atomic backups using LVM snapshots
- **PostgreSQL**: pg_dump or LVM snapshot support
- **Elasticsearch**: Native snapshot API integration
- **MongoDB**: mongodump backup strategy

### 🔧 Advanced Features
- **SSH Key Management**: Automatic SSH key generation and deployment
- **LVM Snapshots**: Atomic database backups without downtime
- **Retention Policies**: Configurable daily/weekly/monthly retention
- **Compression**: Multiple compression algorithms (lz4, zstd, etc.)
- **Deduplication**: Efficient storage with Borg's deduplication
- **Encryption**: Repository-level encryption with secure passphrases
- **Logging**: PSR-3 compliant structured logging
- **CLI Interface**: Beautiful Symfony Console commands

## 📋 Requirements

- **PHP**: 8.3 or higher
- **Extensions**: mysqli, openssl, posix, json
- **BorgBackup**: 1.2.0 or higher
- **MySQL/MariaDB**: For metadata storage
- **SSH Access**: To remote servers

## 📦 Installation

### 1. Clone Repository

```bash
git clone https://github.com/altzone/phpBorg.git
cd phpBorg
```

### 2. Install Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

### 3. Configure Environment

```bash
cp .env.example .env
nano .env  # Edit configuration
```

Required environment variables:
```env
DB_HOST=127.0.0.1
DB_NAME=phpborg
DB_USER=phpborg
DB_PASSWORD=your_secure_password

BORG_BINARY_PATH=/usr/bin/borg
BORG_BACKUP_PATH=/data/backups

APP_SECRET=your_secret_key_here
```

### 4. Setup Database

```bash
mysql -u root -p < backup.sql
```

### 5. Create Log Directory

```bash
sudo mkdir -p /var/log
sudo touch /var/log/phpborg.log
sudo chmod 644 /var/log/phpborg.log
```

## 🚀 Usage

### Add a Server

```bash
./bin/phpborg server:add my-server --port=22 --retention=8
```

This will:
- Test SSH connectivity
- Generate SSH keys
- Install BorgBackup on remote server
- Create local backup repository
- Configure encryption

### List Servers

```bash
./bin/phpborg server:list
```

### Backup Single Server

```bash
# Filesystem backup
./bin/phpborg backup my-server

# MySQL backup
./bin/phpborg backup my-server --type=mysql

# PostgreSQL backup
./bin/phpborg backup my-server --type=postgres

# Elasticsearch backup
./bin/phpborg backup my-server --type=elasticsearch

# MongoDB backup
./bin/phpborg backup my-server --type=mongodb
```

### Full Backup (All Servers)

```bash
./bin/phpborg backup:full
```

### Schedule Automated Backups

Add to crontab:

```cron
# Filesystem backup every night at 2 AM
0 2 * * * /usr/bin/php /path/to/phpBorg/bin/phpborg backup:full >> /var/log/phpborg-cron.log 2>&1

# MySQL backup every 6 hours
0 */6 * * * /usr/bin/php /path/to/phpBorg/bin/phpborg backup my-server --type=mysql
```

## 🏗️ Architecture

### Project Structure

```
phpBorg/
├── bin/
│   └── phpborg              # CLI entry point
├── config/                  # Configuration files
├── src/
│   ├── Application.php      # DI Container
│   ├── Command/             # CLI Commands
│   ├── Config/              # Configuration classes
│   ├── Database/            # Database layer
│   ├── Entity/              # Domain entities
│   ├── Exception/           # Custom exceptions
│   ├── Logger/              # Logging implementation
│   ├── Repository/          # Data repositories
│   └── Service/
│       ├── Backup/          # Backup services
│       ├── Database/        # Database backup strategies
│       ├── Repository/      # Repository management
│       └── Server/          # Server management
├── var/
│   └── log/                 # Application logs
├── vendor/                  # Composer dependencies
├── .env                     # Environment configuration
├── .env.example             # Example configuration
├── composer.json            # Dependencies
└── README.md                # This file
```

### Design Patterns

- **Repository Pattern**: Data access layer abstraction
- **Strategy Pattern**: Multiple backup strategies (MySQL, PostgreSQL, etc.)
- **Dependency Injection**: Clean separation of concerns
- **Factory Pattern**: Object creation
- **Command Pattern**: CLI commands

## 🔒 Security Features

### ✅ Implemented
- ✅ Parameterized SQL queries (no SQL injection)
- ✅ Password hashing with Argon2id
- ✅ Cryptographically secure random generation
- ✅ Environment variable configuration
- ✅ No hardcoded credentials
- ✅ SSH key-based authentication
- ✅ Repository encryption
- ✅ Strict type checking

### 🔐 Best Practices
- All user inputs are validated and sanitized
- Database credentials stored in environment variables
- Passphrases encrypted before storage
- SSH connections with proper options
- Process isolation for command execution

## 📊 Database Schema

The application uses 5 tables:

- **servers**: Server configurations
- **repository**: Borg repository metadata
- **archives**: Backup archives
- **db_info**: Database backup configurations
- **report**: Backup execution reports

## 🎨 Code Quality

### PHP 8.3+ Features Used
- ✅ Strict types (`declare(strict_types=1)`)
- ✅ Constructor property promotion
- ✅ Readonly properties
- ✅ Named arguments
- ✅ Match expressions
- ✅ Union types
- ✅ Attributes (future use)

### Standards Compliance
- ✅ PSR-4: Autoloading
- ✅ PSR-12: Coding style
- ✅ PSR-3: Logging interface principles

## 🐛 Development

### Run Code Quality Checks

```bash
# PHPStan (Static Analysis)
composer phpstan

# PHP CodeSniffer
composer cs-check

# Fix Coding Standards
composer cs-fix

# Run Tests
composer test
```

## 📝 Migration from v1

The legacy files have been preserved with `.legacy` extension. Key changes:

### Breaking Changes
- PHP 8.3+ required (was 7.0+)
- Composer required for autoloading
- Environment variables required
- New command structure

### Migration Steps
1. Install PHP 8.3+
2. Run `composer install`
3. Create `.env` from `.env.example`
4. Update database schema (if needed)
5. Test with `./bin/phpborg server:list`

## 🤝 Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Write tests for new features
4. Ensure code passes quality checks
5. Submit a pull request

## 📜 License

This project is licensed under the GNU General Public License v3.0 - see the [LICENSE](LICENSE) file for details.

## 🙏 Acknowledgments

- [BorgBackup](https://www.borgbackup.org/) - The excellent backup tool
- [Symfony Console](https://symfony.com/doc/current/components/console.html) - CLI framework
- Original phpBorg developers

## 📞 Support

- **Issues**: [GitHub Issues](https://github.com/altzone/phpBorg/issues)
- **Documentation**: See this README
- **BorgBackup Docs**: https://borgbackup.readthedocs.io/

---

**Made with ❤️ for reliable and secure backups**
