-- Tabela de usuários (pacientes e terapeutas compartilham essa tabela)
CREATE TABLE usuarios (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  nome     VARCHAR(100)  NOT NULL,
  email    VARCHAR(150)  NOT NULL UNIQUE,
  senha    VARCHAR(255)  NOT NULL,
  tipo     ENUM('paciente','terapeuta','coordenador') NOT NULL,
  telefone VARCHAR(20),
  criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de terapeutas (dados extras além do usuário)
CREATE TABLE terapeutas (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id   INT NOT NULL,
  especialidade VARCHAR(100) NOT NULL,
  periodo      VARCHAR(20),
  ativo        TINYINT(1) DEFAULT 1,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- Tabela de pacientes (dados extras além do usuário)
CREATE TABLE pacientes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  data_nasc  DATE,
  cpf        VARCHAR(14),
  endereco   VARCHAR(200),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- Tabela de ambientes (salas e macas)
CREATE TABLE ambientes (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nome   VARCHAR(50) NOT NULL,
  tipo   ENUM('sala','maca') NOT NULL,
  ativo  TINYINT(1) DEFAULT 1
);

-- Tabela de plantões (disponibilidade do terapeuta)
CREATE TABLE plantoes (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  terapeuta_id INT NOT NULL,
  data         DATE NOT NULL,
  hora_inicio  TIME NOT NULL,
  hora_fim     TIME NOT NULL,
  ambiente_id  INT,
  FOREIGN KEY (terapeuta_id) REFERENCES terapeutas(id),
  FOREIGN KEY (ambiente_id)  REFERENCES ambientes(id)
);

-- Tabela de ciclos (cada paciente tem um ciclo com o terapeuta)
CREATE TABLE ciclos (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  paciente_id  INT NOT NULL,
  terapeuta_id INT NOT NULL,
  total_sessoes INT DEFAULT 4,
  status       ENUM('ativo','concluido','cancelado') DEFAULT 'ativo',
  criado_em    DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (paciente_id)  REFERENCES pacientes(id),
  FOREIGN KEY (terapeuta_id) REFERENCES terapeutas(id)
);

-- Tabela de agendamentos (sessões marcadas)
CREATE TABLE agendamentos (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  ciclo_id     INT NOT NULL,
  plantao_id   INT NOT NULL,
  numero_sessao INT NOT NULL,
  status       ENUM('agendado','realizado','cancelado') DEFAULT 'agendado',
  observacao   TEXT,
  criado_em    DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ciclo_id)   REFERENCES ciclos(id),
  FOREIGN KEY (plantao_id) REFERENCES plantoes(id)
);

-- ── Dados iniciais para testar ──

-- Ambientes
INSERT INTO ambientes (nome, tipo) VALUES
  ('Sala 01', 'sala'),
  ('Sala 02', 'sala'),
  ('Sala 03', 'sala'),
  ('Maca 1',  'maca'),
  ('Maca 2',  'maca');

-- Usuários de teste (as senhas abaixo são geradas com password_hash do PHP)
-- Senha de todos os usuários de teste: nupics123
INSERT INTO usuarios (nome, email, senha, tipo, telefone) VALUES
  ('Profa. Maria Lima',  'coordenacao@nupics.uern.br', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'coordenador', '84900000001'),
  ('Lucas Ferreira',     'lucas@nupics.uern.br',        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'terapeuta',   '84900000002'),
  ('Ana Costa',          'ana@nupics.uern.br',           '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'terapeuta',   '84900000003'),
  ('Maria Souza',        'maria@email.com',              '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'paciente',    '84900000004'),
  ('João Freitas',       'joao@email.com',               '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'paciente',    '84900000005');

-- Terapeutas
INSERT INTO terapeutas (usuario_id, especialidade, periodo) VALUES
  (2, 'Acupuntura',   '6º período'),
  (3, 'Reiki',        '5º período');

-- Pacientes
INSERT INTO pacientes (usuario_id, cpf) VALUES
  (4, '000.000.000-01'),
  (5, '000.000.000-02');