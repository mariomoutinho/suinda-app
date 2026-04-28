const mockUsers = [
  {
    id: 1,
    name: "Aluno Teste",
    email: "aluno@suinda.com",
    password: "123456",
    role: "student",
    active: true
  },
  {
    id: 2,
    name: "Administrador",
    email: "admin@suinda.com",
    password: "admin123",
    role: "admin",
    active: true
  }
];

const mockDecks = [
  {
    id: 1,
    title: "Biologia Básica",
    description: "Introdução aos conceitos fundamentais de biologia.",
    category: "Biologia",
    totalCards: 3
  },
  {
    id: 2,
    title: "História do Brasil",
    description: "Principais marcos históricos do Brasil.",
    category: "História",
    totalCards: 3
  },
  {
    id: 3,
    title: "Inglês Essencial",
    description: "Vocabulário e expressões básicas do inglês.",
    category: "Idiomas",
    totalCards: 3
  }
];

const mockCards = [
  {
    id: 1,
    deckId: 1,
    question: "O que é célula?",
    answer: "É a unidade estrutural e funcional dos seres vivos."
  },
  {
    id: 2,
    deckId: 1,
    question: "O que é fotossíntese?",
    answer: "É o processo pelo qual plantas produzem alimento usando luz, água e gás carbônico."
  },
  {
    id: 3,
    deckId: 1,
    question: "O que são seres autótrofos?",
    answer: "São seres capazes de produzir seu próprio alimento."
  },
  {
    id: 4,
    deckId: 2,
    question: "Em que ano ocorreu a Independência do Brasil?",
    answer: "Em 1822."
  },
  {
    id: 5,
    deckId: 2,
    question: "Quem foi Dom Pedro I?",
    answer: "Foi o primeiro imperador do Brasil."
  },
  {
    id: 6,
    deckId: 2,
    question: "O que foi a Proclamação da República?",
    answer: "Foi o evento que encerrou o Império e instaurou a República no Brasil, em 1889."
  },
  {
    id: 7,
    deckId: 3,
    question: "Como dizer 'olá' em inglês?",
    answer: "Hello."
  },
  {
    id: 8,
    deckId: 3,
    question: "Como dizer 'obrigado' em inglês?",
    answer: "Thank you."
  },
  {
    id: 9,
    deckId: 3,
    question: "Como dizer 'livro' em inglês?",
    answer: "Book."
  }
];
