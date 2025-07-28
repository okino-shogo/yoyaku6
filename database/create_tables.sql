-- データベースを使用
USE yoyaku_system;

-- テーブル：room_groups（部屋グループ）
CREATE TABLE room_groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- テーブル：room_types（部屋タイプ）
CREATE TABLE room_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- テーブル：rooms（部屋）
CREATE TABLE rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_number VARCHAR(10) NOT NULL,
  room_type_id INT NOT NULL,
  group_id INT,
  capacity_adults INT NOT NULL,
  capacity_children INT DEFAULT 0,
  display_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (room_type_id) REFERENCES room_types(id),
  FOREIGN KEY (group_id) REFERENCES room_groups(id)
);

-- テーブル：plans（プラン）
CREATE TABLE plans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  description TEXT,
  price DECIMAL(10,2) NOT NULL COMMENT 'プラン価格 × (adults + children) で自動計算し、必ず値を提供',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- テーブル：customers（顧客）
CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  kana VARCHAR(100),
  phone VARCHAR(20),
  email VARCHAR(100),
  address TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- テーブル：reservations（予約）
CREATE TABLE reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  plan_id INT NOT NULL,
  room_id INT,
  checkin_date DATE NOT NULL,
  checkout_date DATE NOT NULL,
  adults INT NOT NULL,
  children INT DEFAULT 0,
  price DECIMAL(10,2) NOT NULL,
  payment_status ENUM('unpaid','partial','paid') DEFAULT 'unpaid',
  reservation_status ENUM('unassigned','assigned','checked_in','checked_out','cancelled') DEFAULT 'unassigned',
  source VARCHAR(20),
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (plan_id) REFERENCES plans(id),
  FOREIGN KEY (room_id) REFERENCES rooms(id)
);

-- テーブル：payments（支払い履歴）
CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  payment_method ENUM('cash','card','online','points') NOT NULL,
  paid_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reservation_id) REFERENCES reservations(id)
);

-- 初期データの挿入
-- 部屋グループ
INSERT INTO room_groups (name) VALUES 
('Aグループ'),
('Bグループ');

-- 部屋タイプ
INSERT INTO room_types (name, description) VALUES 
('シングル', '1名様用の部屋'),
('ダブル', '2名様用の部屋'),
('ツイン', '2つのベッドがある部屋'),
('ファミリー', '家族向けの広い部屋');

-- プラン
INSERT INTO plans (name, description, price) VALUES 
('素泊まり', '食事なしのシンプルプラン', 5000.00),
('朝食付き', '朝食が含まれるプラン', 6500.00),
('2食付き', '朝食・夕食が含まれるプラン', 9000.00);

-- 部屋データ
INSERT INTO rooms (room_number, room_type_id, group_id, capacity_adults, capacity_children, display_order) VALUES 
('201', 1, 1, 1, 0, 1),
('202', 2, 1, 2, 1, 2),
('203', 2, 1, 2, 1, 3),
('204', 3, 1, 2, 1, 4),
('301', 1, 2, 1, 0, 5),
('302', 2, 2, 2, 1, 6),
('303', 4, 2, 4, 2, 7),
('304', 4, 2, 4, 2, 8); 