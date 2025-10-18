-- Users table
CREATE TABLE users (
    id_user INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    gender TINYINT NOT NULL,  -- 0=male, 1=female
    age INT NOT NULL,
    height FLOAT NOT NULL,  -- cm
    weight FLOAT NOT NULL,  -- kg
    activity TINYINT NOT NULL,  -- 1=sedentary, 2=light, 3=moderate, 4=active
    stress TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);