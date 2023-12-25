### Hexlet tests and linter status:
[![Actions Status](https://github.com/Smol-An/php-project-9/actions/workflows/hexlet-check.yml/badge.svg)](https://github.com/Smol-An/php-project-9/actions)
[![PHP CI](https://github.com/Smol-An/php-project-9/actions/workflows/workflow.yml/badge.svg)](https://github.com/Smol-An/php-project-9/actions/workflows/workflow.yml)
[![Maintainability](https://api.codeclimate.com/v1/badges/694b3e0bedc97fd11800/maintainability)](https://codeclimate.com/github/Smol-An/php-project-9/maintainability)

### About the project
Page Analyzer is a full-fledged web application based on the Slim framework that analyzes web pages for SEO suitability and saves the data to a database.


### Requirements
* PHP >= 8.1
* Composer >= 2.5.5
* PostgreSQL >= 16.1
* GNU Make >= 4.3

### Setup
```
$ git clone https://github.com/Smol-An/php-project-9.git
$ cd php-project-9
$ make install
```

### Run
Create a database and upload the database.sql file into it.

```
$ make start
```

Open http://localhost:8000/ in your browser.

### Usage
Open in browser: https://seo-analyzer-7eis.onrender.com/