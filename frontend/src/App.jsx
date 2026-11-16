import { Route, Routes } from 'react-router'
import AppLayout from './components/AppLayout.jsx'
import AccountPage from './pages/AccountPage.jsx'
import HomePage from './pages/HomePage.jsx'
import NotFoundPage from './pages/NotFoundPage.jsx'

import AdminPage from './pages/AdminPage.jsx'
import CreateCoursePage from './pages/CreateCoursePage.jsx'

export default function App() {
  return (
    <Routes>
      <Route element={<AppLayout />}>
        <Route index element={<HomePage />} />
        <Route path="account" element={<AccountPage />} />
        <Route path="*" element={<NotFoundPage />} />
        <Route path="courses/create" element={<CreateCoursePage />} />
        <Route path="admin" element={<AdminPage />} />
      </Route>
    </Routes>
  )
}
