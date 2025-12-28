-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Oct 08, 2025 at 01:05 PM
-- Server version: 10.11.14-MariaDB-cll-lve
-- PHP Version: 8.4.11

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


CREATE TABLE IF NOT EXISTS consumption_history (
    id VARCHAR(255) PRIMARY KEY,
    user_id INT NOT NULL,
    food_id INT NOT NULL,
    urt_id INT NOT NULL,
    portion_quantity DOUBLE NOT NULL,
    percentage DOUBLE NOT NULL,
    date_report VARCHAR(50) NOT NULL,
    updated_at VARCHAR(50) NOT NULL,
    
    -- Foreign Key Constraints
    CONSTRAINT fk_consumption_user 
        FOREIGN KEY (user_id) 
        REFERENCES users(id_user) 
        ON DELETE CASCADE,
    
    CONSTRAINT fk_consumption_food 
        FOREIGN KEY (food_id) 
        REFERENCES data_makanan(id) 
        ON DELETE CASCADE,
    
    CONSTRAINT fk_consumption_urt 
        FOREIGN KEY (urt_id) 
        REFERENCES urt_list(id) 
        ON DELETE CASCADE,
    
    -- Indexes for better query performance
    INDEX idx_user_id (user_id),
    INDEX idx_food_id (food_id),
    INDEX idx_urt_id (urt_id),
    INDEX idx_date_report (date_report)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;