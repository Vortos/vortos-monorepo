SELECT 'CREATE DATABASE squaura'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'squaura')\gexec