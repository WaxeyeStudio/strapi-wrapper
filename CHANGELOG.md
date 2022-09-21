# Changelog

All notable changes to `strapi-wrapper` will be documented in this file.

### 0.1.0 - 2022-06-22

- initial release

### 0.1.1 - 2022-09-22

- For strapi v4 add ability to deeply populate relationship queries
  ```ie $collection->populate(['City', 'Person'])```
  will populate the records of those two fields
