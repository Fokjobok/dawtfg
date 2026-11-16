import styles from './Container.module.scss'

export default function Container({
  as: Element = 'div',
  className = '',
  ...props
}) {
  return <Element className={`${styles.container} ${className}`} {...props} />
}
